<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Arr;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionTrustedKey;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
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
 *     binding is what makes a signature unreplayable onto a different artifact,
 *     a different version, or a manifest edited after signing.
 *
 * There are no third-party publishers. A self-added repository parses, but its
 * packages are untrusted and carry the same restrictions as an unsigned local
 * install: no hooks, no queues, no dangerous permissions. Prose warnings do not
 * stop anybody; a capability gate does.
 */
class ExtensionSignatureService
{
    /** Capabilities an unverified package may never hold. */
    private const RESTRICTED_CAPABILITIES = ['hooks', 'queues', 'dangerous permissions'];

    public function __construct(private ExtensionManifestCanonicalizer $canonicalizer)
    {
    }

    /**
     * Whether signatures are actually enforced right now.
     *
     * Enforcement needs a pinned root: with none configured there is no
     * authority to verify anything against, and requiring signatures would make
     * the extension system unusable rather than safer. Pinning a root turns
     * enforcement on by itself — there is no second switch to forget.
     */
    public function signingRequired(): bool
    {
        return (bool) config('extensions.signing.require_signature', true)
            && $this->rootPublicKey() !== null;
    }

    public function rootPinned(): bool
    {
        return $this->rootPublicKey() !== null;
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

            ExtensionTrustedKey::query()->updateOrCreate(
                ['key_id' => $keyId],
                [
                    'public_key' => $publicKey,
                    'fingerprint' => hash('sha256', $decoded),
                    'repository_id' => $repositoryId,
                    'label' => Arr::get($key, 'label'),
                    'valid_from' => Arr::get($key, 'validFrom'),
                    'valid_until' => Arr::get($key, 'validUntil'),
                    // Revocation is one-way. A registry that stops advertising
                    // a revocation must not un-revoke the key.
                    'revoked_at' => $isRevoked ? now() : null,
                ]
            );

            $isRevoked ? $revoked++ : $admitted++;
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
    ): array {
        $canonical = $this->canonicalizer->canonicalize($rawManifest);
        $signature = $manifest->signature();
        $keyId = $manifest->signingKeyId();

        // No root pinned: nothing can be verified against anything, so the
        // package is admitted as unverified rather than refused. It still
        // carries the unverified capability restrictions, so the panel is not
        // simply trusting it — it is declining to let it run code on core's
        // behalf.
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

        $key = ExtensionTrustedKey::query()->where('key_id', $keyId)->first();

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
     * A package the panel cannot attribute to anybody must not be able to run
     * code on core's deletion path, dispatch background work, or contribute a
     * permission marked dangerous.
     */
    public function assertCapabilitiesAllowedUnverified(ExtensionManifest $manifest): void
    {
        $held = [];

        if ($manifest->capabilities->hooks !== []) {
            $held[] = 'hooks';
        }

        if ($manifest->capabilities->queues !== []) {
            $held[] = 'queues';
        }

        foreach ($manifest->capabilities->adminPermissions as $permission) {
            if ($permission->dangerous) {
                $held[] = 'dangerous permissions';
                break;
            }
        }

        if ($held === []) {
            return;
        }

        throw new DisplayException(sprintf('An unverified package may not declare %s. Allowed for unverified packages: everything except %s.', implode(' or ', $held), implode(', ', self::RESTRICTED_CAPABILITIES)));
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

        // An unsigned package is admissible only in the narrow shape that
        // cannot execute on core's behalf.
        $this->assertCapabilitiesAllowedUnverified($manifest);

        $this->audit($manifest, ExtensionSignatureAudit::VERDICT_UNSIGNED, null, $archiveSha256, $canonical, 'Operator acknowledged an unsigned install.', $initiator);

        return ['state' => 'unsigned_acknowledged', 'keyId' => null, 'verifiedAt' => null];
    }

    /**
     * Refuse a version at or below the highest already recorded.
     *
     * Without this, an attacker who can serve an old *validly signed* release
     * can downgrade an extension to one with a known flaw — the signature is
     * genuine, so nothing else in the chain objects.
     */
    /**
     * Whether a release advertised as signed by $keyId could verify here.
     *
     * A catalog-time filter, not a gate. It lets the catalog skip a release the
     * install would refuse — an unknown, expired or revoked key — instead of
     * offering it and failing partway through. A null key id means the release
     * advertises no signature, which is fine on a panel with no root pinned and
     * caught at install by verify() on one that has.
     */
    public function isReleaseKeyUsable(?string $keyId): bool
    {
        if ($keyId === null || $keyId === '' || !$this->signingRequired()) {
            return true;
        }

        $key = ExtensionTrustedKey::query()->where('key_id', $keyId)->first();

        return $key !== null && $key->isUsable();
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
        $configured = (string) config('extensions.signing.root_public_key', '');

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
        $pinned = strtolower((string) config('extensions.signing.root_fingerprint', ''));

        if ($pinned !== '' && !hash_equals($pinned, hash('sha256', $decoded))) {
            return null;
        }

        return $decoded;
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
