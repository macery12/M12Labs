<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Arr;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionTrustedKey;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

/**
 * Decides whether a package artifact may be installed, and records why.
 *
 * The trust chain has exactly two links, and the panel pins only the first:
 *
 *  1. An **offline root key**, in config and never on a build machine, signs a
 *     record for each release key. The panel admits a release key only when
 *     that root signature verifies over the canonical key record — which is
 *     what stops a compromised registry from listing a key of its own.
 *  2. The **release key** signs a domain-separated message naming the
 *     extension, version, canonical manifest hash and archive hash. That
 *     binding is what makes a signature unreplayable onto a different package,
 *     version, or manifest. The signed manifest in turn contains every
 *     installed file's checksum.
 *
 * There are no third-party publishers. A self-added repository parses, but its
 * packages are untrusted and carry the same restrictions as an unsigned local
 * install: they cannot expose executable or privileged capabilities. Prose
 * warnings do not stop anybody; a capability gate does.
 */
class ExtensionSignatureService
{
    /** Capabilities an unverified package may never hold. */
    private const RESTRICTED_CAPABILITIES = [
        'routes.client',
        'routes.admin',
        'pages.server',
        'pages.admin',
        'database.migrations',
        'schedule',
        'commands',
        'hooks',
        'queues',
        'permissions.admin',
        'privileged',
        'streams',
    ];

    public function __construct(private ExtensionManifestCanonicalizer $canonicalizer)
    {
    }

    /**
     * Whether signatures are actually enforced right now.
     *
     * This reports policy, not whether its trust anchor is healthy. If the
     * operator enables enforcement while the root configuration is missing or
     * invalid, verification fails closed instead of quietly changing policy to
     * unsigned mode.
     */
    public function signingRequired(): bool
    {
        return (bool) config('extensions.signing.require_signature', true);
    }

    public function rootPinned(): bool
    {
        return $this->rootPublicKey() !== null;
    }

    /** The validated identity of the currently configured signing root. */
    public function currentRootFingerprint(): ?string
    {
        $root = $this->rootPublicKey();

        return $root === null ? null : hash('sha256', $root);
    }

    /**
     * Admit the release keys a registry presents, each authorized by the pinned
     * offline root.
     *
     * Keys the root has not signed are ignored rather than rejected loudly:
     * a registry is free to advertise anything, and the only thing that matters
     * is which of it the root vouches for.
     *
     * @param array<int, array<string, mixed>> $keys registry.keys[]
     *
     * @return array{admitted: int, rejected: int, revoked: int}
     */
    public function syncRegistryKeys(array $keys, ?int $repositoryId = null): array
    {
        $rootKey = $this->rootPublicKey();
        $rootFingerprint = $rootKey === null ? null : hash('sha256', $rootKey);
        $admitted = $rejected = $revoked = 0;

        foreach ($keys as $key) {
            $keyId = (string) Arr::get($key, 'keyId', '');
            $publicKey = (string) Arr::get($key, 'publicKey', '');
            $rootSignature = (string) Arr::get($key, 'rootSignature', '');

            if ($keyId === '' || $publicKey === '' || $rootSignature === '') {
                ++$rejected;

                continue;
            }

            if ($rootKey === null || !$this->verifyDetached($this->keyRecordMessage($key), $rootSignature, $rootKey)) {
                ++$rejected;

                continue;
            }

            $decoded = $this->decodeKey($publicKey);
            if ($decoded === null) {
                ++$rejected;

                continue;
            }

            $isRevoked = (bool) Arr::get($key, 'revoked', false);
            $fingerprint = hash('sha256', $decoded);

            $trustedKey = ExtensionTrustedKey::query()->firstOrNew(['key_id' => $keyId]);
            if ($trustedKey->exists) {
                $storedKey = $this->decodeKey((string) $trustedKey->public_key);
                if ($storedKey === null
                    || !hash_equals($fingerprint, hash('sha256', $storedKey))
                    || !hash_equals($fingerprint, (string) $trustedKey->fingerprint)) {
                    // Installed packages bind to key_id. Permitting another
                    // root to replace that id with different key bytes would
                    // make old packages appear authorized by the new root.
                    ++$rejected;

                    continue;
                }
            }
            $wasRevoked = $trustedKey->exists && $trustedKey->revoked_at !== null;

            $trustedKey->forceFill([
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'root_fingerprint' => $rootFingerprint,
                'repository_id' => $repositoryId,
                'label' => Arr::get($key, 'label'),
                'valid_from' => Arr::get($key, 'validFrom'),
                'valid_until' => Arr::get($key, 'validUntil'),
                // A root-signed active record may be older than a root-signed
                // revocation. Once observed, revocation is therefore
                // monotonic: replaying the older record cannot clear it.
                'revoked_at' => $wasRevoked || $isRevoked
                    ? ($trustedKey->revoked_at ?? now())
                    : null,
            ])->save();

            ($wasRevoked || $isRevoked) ? $revoked++ : $admitted++;
        }

        return ['admitted' => $admitted, 'rejected' => $rejected, 'revoked' => $revoked];
    }

    /**
     * Verify one artifact and return the signature state to persist.
     *
     * @param array<string, mixed> $rawManifest the manifest as parsed from the archive
     *
     * @return array{state: string, keyId: ?string, verifiedAt: ?\Carbon\Carbon}
     *
     * @throws DisplayException when the artifact must not be installed
     */
    public function verify(
        ExtensionManifest $manifest,
        array $rawManifest,
        string $archiveSha256,
        bool $acknowledgeUnsigned = false,
        ?string $initiator = null,
        ?string $canonicalManifest = null,
    ): array {
        $canonical = $canonicalManifest ?? $this->canonicalizer->canonicalize($rawManifest);
        $signature = $manifest->signature();
        $keyId = $manifest->signingKeyId();

        // Enabling signature enforcement without a valid trust anchor is a
        // configuration failure, never an implicit request to allow unsigned
        // code. This check precedes every artifact-specific branch so even an
        // unsigned acknowledgement cannot bypass it.
        if ($this->signingRequired() && !$this->rootPinned()) {
            $reason = 'Signature verification is required, but the configured root public key and fingerprint are missing, malformed, or do not match.';
            $this->audit(
                $manifest,
                ExtensionSignatureAudit::VERDICT_REJECTED,
                $keyId,
                $archiveSha256,
                $canonical,
                $reason,
                $initiator
            );

            throw new DisplayException($reason);
        }

        // Explicit development mode: without a usable root there is no
        // authority against which to check a signature. This behavior exists
        // only when signature enforcement itself is deliberately disabled.
        if (!$this->rootPinned()) {
            $this->audit(
                $manifest,
                ExtensionSignatureAudit::VERDICT_UNSIGNED,
                $keyId,
                $archiveSha256,
                $canonical,
                'No signing root is pinned on this panel; the signature was not checked.',
                $initiator
            );

            return ['state' => 'unsigned_acknowledged', 'keyId' => null, 'verifiedAt' => null];
        }

        if ($signature === null || $keyId === null) {
            return $this->handleUnsigned($manifest, $canonical, $archiveSha256, $acknowledgeUnsigned, $initiator);
        }

        $key = $this->trustedKeyForCurrentRoot($keyId);

        if ($key === null) {
            $this->audit($manifest, ExtensionSignatureAudit::VERDICT_REJECTED, $keyId, $archiveSha256, $canonical, 'Unknown signing key.', $initiator);

            throw new DisplayException(sprintf('This package is signed with key [%s], which this panel does not trust. Refresh the repository, or the key was never authorized by the root.', $keyId));
        }

        if (!$key->isUsable()) {
            $this->audit($manifest, ExtensionSignatureAudit::VERDICT_REVOKED, $keyId, $archiveSha256, $canonical, 'Signing key revoked or expired.', $initiator);

            throw new DisplayException(sprintf('The key that signed this package [%s] is revoked or outside its validity window.', $keyId));
        }

        $decoded = $this->decodeKey($key->public_key);
        $message = $this->canonicalizer->signingMessage($manifest->id, $manifest->version, $canonical);

        if ($decoded === null || !$this->verifyDetached($message, $signature, $decoded)) {
            $this->audit($manifest, ExtensionSignatureAudit::VERDICT_REJECTED, $keyId, $archiveSha256, $canonical, 'Signature did not verify.', $initiator);

            throw new DisplayException('The package signature does not match its contents. The archive or its manifest was modified after signing.');
        }

        $this->assertNotARollback($manifest, $initiator);

        $this->audit($manifest, ExtensionSignatureAudit::VERDICT_VERIFIED, $keyId, $archiveSha256, $canonical, null, $initiator);

        return ['state' => 'verified', 'keyId' => $keyId, 'verifiedAt' => now()];
    }

    /**
     * The capabilities an unverified package is not allowed to hold.
     *
     * A package the panel cannot attribute to anybody must not expose any
     * executable surface or contribute any administrator permission. The
     * restriction is enforced both before install and again while constructing
     * the runtime plan, so packages admitted under older policy become inert.
     */
    public function assertCapabilitiesAllowedUnverified(ExtensionManifest $manifest): void
    {
        $held = $this->restrictedCapabilitiesForUnverified($manifest->capabilities);

        if ($held === []) {
            return;
        }

        throw new DisplayException(sprintf('An unverified package may not declare executable or privileged capabilities: %s. Sign the package, or remove those capabilities.', implode(', ', $held)));
    }

    /**
     * @return array<int, string> prohibited capability names the set holds
     */
    public function restrictedCapabilitiesForUnverified(ExtensionCapabilitySet $capabilities): array
    {
        $held = [];

        if ($capabilities->clientRoutes) {
            $held[] = 'routes.client';
        }
        if ($capabilities->adminRoutes) {
            $held[] = 'routes.admin';
        }
        if ($capabilities->serverPages !== []) {
            $held[] = 'pages.server';
        }
        if ($capabilities->adminPages !== []) {
            $held[] = 'pages.admin';
        }
        if ($capabilities->migrations) {
            $held[] = 'database.migrations';
        }
        if ($capabilities->schedule) {
            $held[] = 'schedule';
        }
        if ($capabilities->commands !== []) {
            $held[] = 'commands';
        }
        if ($capabilities->hooks !== []) {
            $held[] = 'hooks';
        }
        if ($capabilities->queues !== []) {
            $held[] = 'queues';
        }
        if ($capabilities->adminPermissions !== []) {
            $held[] = 'permissions.admin';
        }
        // Authority core hands back to the package. Of everything on this list
        // it is the one an unverified package has least business holding.
        if ($capabilities->privileged !== []) {
            $held[] = 'privileged';
        }
        // A held worker for up to an hour, from a package nobody vouched for.
        if ($capabilities->streams !== []) {
            $held[] = 'streams';
        }

        return array_values(array_intersect(self::RESTRICTED_CAPABILITIES, $held));
    }

    /**
     * Re-check trusted keys against a refreshed registry and mark any installed
     * package whose key was revoked. Blocks enabling; does not uninstall.
     *
     * @return array<int, string> extension ids newly marked revoked
     */
    public function markRevokedInstalls(): array
    {
        $revokedKeyIds = ExtensionTrustedKey::query()
            ->whereNotNull('revoked_at')
            ->pluck('key_id')
            ->all();

        if ($revokedKeyIds === []) {
            return [];
        }

        $affected = ExtensionPackage::query()
            ->whereIn('signature_key_id', $revokedKeyIds)
            ->where('signature_state', '!=', 'revoked')
            ->get();

        foreach ($affected as $package) {
            $package->update([
                'signature_state' => 'revoked',
                'state_reason' => 'The key that signed this package has been revoked.',
            ]);
        }

        return $affected->pluck('extension_id')->all();
    }

    /**
     * @return array{state: string, keyId: ?string, verifiedAt: ?\Carbon\Carbon}
     */
    private function handleUnsigned(
        ExtensionManifest $manifest,
        string $canonical,
        string $archiveSha256,
        bool $acknowledgeUnsigned,
        ?string $initiator,
    ): array {
        if (!$acknowledgeUnsigned || !config('extensions.signing.allow_unsigned_local', false)) {
            $this->audit($manifest, ExtensionSignatureAudit::VERDICT_REJECTED, null, $archiveSha256, $canonical, 'Unsigned package.', $initiator);

            throw new DisplayException('This package is not signed. Install it from a signed release, or install it from a local archive with the unsigned acknowledgement.');
        }

        // With enforcement enabled, an unsigned package is admissible only in
        // the narrow shape that cannot execute on core's behalf. Explicitly
        // disabling enforcement remains available for local development.
        if ($this->signingRequired()) {
            $this->assertCapabilitiesAllowedUnverified($manifest);
        }

        $this->audit($manifest, ExtensionSignatureAudit::VERDICT_UNSIGNED, null, $archiveSha256, $canonical, 'Operator acknowledged an unsigned install.', $initiator);

        return ['state' => 'unsigned_acknowledged', 'keyId' => null, 'verifiedAt' => null];
    }

    /**
     * Whether a release advertised as signed by $keyId could verify here.
     *
     * A catalog-time filter, not a gate. It lets the catalog skip a release the
     * install would refuse — an unknown, expired or revoked key — instead of
     * offering it and failing partway through. When enforcement is enabled, a
     * missing key id or unusable root is rejected here as well as by verify().
     */
    public function isReleaseKeyUsable(?string $keyId): bool
    {
        if (!$this->signingRequired()) {
            return true;
        }

        return $this->isTrustedKeyUsable($keyId);
    }

    /** A current-root key that is active now, for catalog and runtime gates. */
    public function isTrustedKeyUsable(?string $keyId): bool
    {
        if ($keyId === null || $keyId === '') {
            return false;
        }

        return $this->trustedKeyForCurrentRoot($keyId)?->isUsable() === true;
    }

    /**
     * Resolve several runtime/package checks with one trusted-key query.
     *
     * @param array<int, mixed> $keyIds
     *
     * @return array<int, string>
     */
    public function usableTrustedKeyIds(array $keyIds): array
    {
        $rootFingerprint = $this->currentRootFingerprint();
        $keyIds = array_values(array_unique(array_filter($keyIds, fn ($id): bool => is_string($id) && $id !== '')));

        if ($rootFingerprint === null || $keyIds === []) {
            return [];
        }

        $now = now();

        return ExtensionTrustedKey::query()
            ->whereIn('key_id', $keyIds)
            ->where('root_fingerprint', $rootFingerprint)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $now))
            ->pluck('key_id')
            ->all();
    }

    /**
     * Whether installing $version would be a rollback for $extensionId.
     *
     * The read-only half of assertNotARollback, so the catalog can decline to
     * advertise a version the installer is going to refuse. The install-time
     * check remains authoritative — this one is advisory and unauthenticated,
     * since it reads a version string the repository supplied.
     */
    public function isRollback(string $extensionId, string $version): bool
    {
        $seen = ExtensionSignatureAudit::query()
            ->where('extension_id', $extensionId)
            ->where('verdict', ExtensionSignatureAudit::VERDICT_VERIFIED)
            ->pluck('version')
            ->all();

        foreach ($seen as $installed) {
            if (version_compare($this->comparable($version), $this->comparable((string) $installed), '<')) {
                return true;
            }
        }

        return false;
    }

    private function assertNotARollback(ExtensionManifest $manifest, ?string $initiator): void
    {
        $highest = ExtensionSignatureAudit::query()
            ->where('extension_id', $manifest->id)
            ->where('verdict', ExtensionSignatureAudit::VERDICT_VERIFIED)
            ->pluck('version')
            ->all();

        foreach ($highest as $seen) {
            if (version_compare($this->comparable($manifest->version), $this->comparable($seen), '<')) {
                throw new DisplayException(sprintf('Version %s is older than %s, which this panel has already installed. Refusing a rollback.', $manifest->version, $seen));
            }
        }
    }

    /** Strip a leading v so version_compare sees a plain semver. */
    private function comparable(string $version): string
    {
        return ltrim($version, 'vV');
    }

    /**
     * The exact bytes the offline root signs for a key record. Domain-separated
     * so a root signature over a key can never be replayed as one over an
     * artifact.
     *
     * @param array<string, mixed> $key
     */
    private function keyRecordMessage(array $key): string
    {
        return implode("\n", [
            'm12labs-ext-key-v1',
            (string) Arr::get($key, 'keyId', ''),
            (string) Arr::get($key, 'publicKey', ''),
            (string) Arr::get($key, 'validFrom', ''),
            (string) Arr::get($key, 'validUntil', ''),
            Arr::get($key, 'revoked', false) ? 'revoked' : 'active',
        ]);
    }

    private function rootPublicKey(): ?string
    {
        $configured = trim((string) config('extensions.signing.root_public_key', ''));

        if ($configured === '') {
            return null;
        }

        $decoded = $this->decodeKey($configured);

        if ($decoded === null) {
            return null;
        }

        // The fingerprint pin is the actual root of trust: an operator can
        // compare it out of band, and a swapped config value fails here rather
        // than silently trusting a different root.
        $pinned = strtolower(trim((string) config('extensions.signing.root_fingerprint', '')));

        if (!preg_match('/^[a-f0-9]{64}$/', $pinned)
            || !hash_equals($pinned, hash('sha256', $decoded))) {
            return null;
        }

        return $decoded;
    }

    private function trustedKeyForCurrentRoot(string $keyId): ?ExtensionTrustedKey
    {
        $rootFingerprint = $this->currentRootFingerprint();
        if ($rootFingerprint === null) {
            return null;
        }

        $key = ExtensionTrustedKey::query()->where('key_id', $keyId)->first();

        return $key?->isAuthorizedBy($rootFingerprint) === true ? $key : null;
    }

    private function decodeKey(string $base64): ?string
    {
        $raw = base64_decode($base64, true);

        return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    private function verifyDetached(string $message, string $signatureBase64, string $publicKey): bool
    {
        $signature = base64_decode($signatureBase64, true);

        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    private function audit(
        ExtensionManifest $manifest,
        string $verdict,
        ?string $keyId,
        string $archiveSha256,
        string $canonical,
        ?string $reason,
        ?string $initiator,
    ): void {
        try {
            ExtensionSignatureAudit::query()->create([
                'extension_id' => $manifest->id,
                'version' => $manifest->version,
                'verdict' => $verdict,
                'key_id' => $keyId,
                'archive_sha256' => strtolower($archiveSha256),
                'canonical_manifest_sha256' => hash('sha256', $canonical),
                'reason' => $reason,
                'initiator' => $initiator,
            ]);
        } catch (\Throwable $exception) {
            // The audit must not be the reason an install fails; a missing row
            // is worse than a failed install only in hindsight.
            report($exception);
        }
    }
}
