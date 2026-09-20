<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator;

final readonly class ExtensionPackageIntegrityResult
{
    /**
     * @param array<int, string> $missingFiles
     * @param array<int, string> $modifiedFiles
     */
    public function __construct(
        public bool $valid,
        public bool $manifestAuthentic,
        public ?ExtensionManifest $manifest,
        public ?string $canonicalManifestSha256,
        public array $missingFiles = [],
        public array $modifiedFiles = [],
        public ?string $reason = null,
    ) {
    }

    public function trackedFiles(): int
    {
        return $this->manifest === null ? 0 : count($this->manifest->files);
    }
}

/**
 * Runtime enforcement of the publisher-authenticated file list.
 *
 * The database's extension_package_files rows remain useful rollback records,
 * but they are not an integrity authority: a database writer could edit those
 * checksums beside the package. Runtime hashes instead come from the exact
 * retained manifest document after its release signature is re-verified.
 */
class ExtensionPackageIntegrityService
{
    private const QUARANTINE_PREFIX = '[runtime-integrity] ';

    public function __construct(
        private ExtensionManifestParser $parser,
        private ExtensionCapabilityFileValidator $capabilityFileValidator,
        private ExtensionManifestCanonicalizer $canonicalizer,
        private ExtensionSignatureService $signatureService,
    ) {
    }

    /**
     * Verify the signed manifest and every file it names, then quarantine on
     * failure. A package quarantined specifically by this service is restored
     * to enabled after the authentic bytes are put back; unrelated failed
     * lifecycle states are never changed here.
     */
    public function enforce(ExtensionPackage $package): ?ExtensionManifest
    {
        $result = $this->inspect($package);

        if (!$result->valid) {
            $this->quarantine($package, $result);

            return null;
        }

        $this->restoreIntegrityQuarantine($package, $result);

        return $result->manifest;
    }

    public function isIntegrityQuarantined(ExtensionPackage $package): bool
    {
        return $package->state === 'failed'
            && str_starts_with((string) $package->state_reason, self::QUARANTINE_PREFIX);
    }

    public function inspect(ExtensionPackage $package): ExtensionPackageIntegrityResult
    {
        try {
            $document = $this->manifestDocument($package);
            $raw = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                return $this->failure('The retained signed manifest is not a JSON object.');
            }

            $manifest = $this->parser->parse(
                $raw,
                (string) $package->extension_id,
                (string) $package->installed_version,
            );
            $this->capabilityFileValidator->assertMatchesFiles($manifest);

            $canonical = $this->canonicalizer->canonicalizeJson($document);
            $canonicalHash = hash('sha256', $canonical);
            $authentic = $package->signature_state === 'verified'
                && $this->signatureService->reverifyInstalledManifest(
                    $manifest,
                    $canonical,
                    $package->signature_key_id,
                );

            // Executable production packages must get their expected hashes
            // from a presently valid publisher signature. Explicit unsigned
            // development mode retains its documented behavior, while still
            // checking the bytes against the retained install manifest.
            if ($package->signature_state === 'verified' && !$authentic) {
                return $this->failure(
                    'The retained manifest signature no longer verifies.',
                    manifest: $manifest,
                    canonicalHash: $canonicalHash,
                );
            }

            $missing = [];
            $modified = [];

            foreach ($manifest->files as $file) {
                $path = (string) $file['path'];
                $absolute = base_path($path);

                // Install extraction never creates links. Rejecting one here
                // avoids following a post-install link outside the package.
                if (is_link($absolute) || !is_file($absolute)) {
                    $missing[] = $path;

                    continue;
                }

                $actual = hash_file('sha256', $absolute);
                if (!is_string($actual) || !hash_equals((string) $file['sha256'], $actual)) {
                    $modified[] = $path;
                }
            }

            if ($missing !== [] || $modified !== []) {
                $first = $missing[0] ?? $modified[0];
                $kind = $missing !== [] ? 'missing' : 'modified';

                return new ExtensionPackageIntegrityResult(
                    valid: false,
                    manifestAuthentic: $authentic,
                    manifest: $manifest,
                    canonicalManifestSha256: $canonicalHash,
                    missingFiles: $missing,
                    modifiedFiles: $modified,
                    reason: sprintf('Signed package file is %s: %s.', $kind, $first),
                );
            }

            return new ExtensionPackageIntegrityResult(
                valid: true,
                manifestAuthentic: $authentic,
                manifest: $manifest,
                canonicalManifestSha256: $canonicalHash,
            );
        } catch (\Throwable $exception) {
            return $this->failure('The retained signed manifest is invalid: ' . $exception->getMessage());
        }
    }

    private function manifestDocument(ExtensionPackage $package): string
    {
        if (is_string($package->signed_manifest) && trim($package->signed_manifest) !== '') {
            return $package->signed_manifest;
        }

        // Rows installed before signed_manifest was introduced are backfilled
        // by the migration. Retain a safe fallback for a migration interrupted
        // between adding and filling the column; verification still fails
        // closed if the reconstructed JSON differs from what was signed.
        return json_encode(
            $package->manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function failure(
        string $reason,
        ?ExtensionManifest $manifest = null,
        ?string $canonicalHash = null,
    ): ExtensionPackageIntegrityResult {
        return new ExtensionPackageIntegrityResult(
            valid: false,
            manifestAuthentic: false,
            manifest: $manifest,
            canonicalManifestSha256: $canonicalHash,
            reason: $reason,
        );
    }

    private function quarantine(ExtensionPackage $package, ExtensionPackageIntegrityResult $result): void
    {
        $reason = self::QUARANTINE_PREFIX . Str::limit(
            $result->reason ?? 'Installed package integrity verification failed.',
            900,
            '',
        );

        $changed = ExtensionPackage::query()
            ->whereKey($package->getKey())
            ->where('state', 'enabled')
            ->update(['state' => 'failed', 'state_reason' => $reason]);

        $package->state = 'failed';
        $package->state_reason = $reason;

        if ($changed !== 1) {
            return;
        }

        $this->audit($package, ExtensionSignatureAudit::VERDICT_INTEGRITY_FAILED, $result, $reason);
        Log::critical('Extension quarantined after runtime integrity verification failed.', [
            'extension' => $package->extension_id,
            'version' => $package->installed_version,
            'missing_files' => $result->missingFiles,
            'modified_files' => $result->modifiedFiles,
            'reason' => $result->reason,
        ]);
    }

    private function restoreIntegrityQuarantine(
        ExtensionPackage $package,
        ExtensionPackageIntegrityResult $result,
    ): void {
        if (!$this->isIntegrityQuarantined($package)) {
            return;
        }

        $changed = ExtensionPackage::query()
            ->whereKey($package->getKey())
            ->where('state', 'failed')
            ->where('state_reason', 'like', self::QUARANTINE_PREFIX . '%')
            ->update(['state' => 'enabled', 'state_reason' => null]);

        if ($changed !== 1) {
            return;
        }

        $package->state = 'enabled';
        $package->state_reason = null;
        $this->audit(
            $package,
            ExtensionSignatureAudit::VERDICT_INTEGRITY_RESTORED,
            $result,
            'Authentic installed package bytes were restored.',
        );
        Log::notice('Extension runtime integrity quarantine cleared after authentic bytes were restored.', [
            'extension' => $package->extension_id,
            'version' => $package->installed_version,
        ]);
    }

    private function audit(
        ExtensionPackage $package,
        string $verdict,
        ExtensionPackageIntegrityResult $result,
        string $reason,
    ): void {
        try {
            ExtensionSignatureAudit::query()->create([
                'extension_id' => $package->extension_id,
                'version' => $package->installed_version,
                'verdict' => $verdict,
                'key_id' => $package->signature_key_id,
                'archive_sha256' => $package->package_checksum,
                'canonical_manifest_sha256' => $result->canonicalManifestSha256,
                'reason' => Str::limit($reason, 191, ''),
                'initiator' => 'runtime-integrity',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
