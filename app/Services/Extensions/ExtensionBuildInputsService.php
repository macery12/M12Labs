<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\Log;
use Everest\Models\ExtensionPackage;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifest;

/**
 * Decides which extension packages the next frontend build may compile, and
 * refuses to build over tampered frontend sources.
 *
 * The build used to glob every directory under
 * `frontend/src/extensions/packages/`: an orphan directory with no package row
 * -- an uninstall that left files behind, or anything dropped there by hand --
 * was compiled into the panel with no signature or database check. That is
 * the post-install-tampering case the extension threat model is about. Now the
 * build only includes packages that have a row, and only once their frontend
 * files match the signed manifest they were installed from.
 *
 * The list is a build input, written right before a build and read by it --
 * not a runtime manifest cache. Nothing reads it at request time; the runtime
 * plan stays a live read.
 */
class ExtensionBuildInputsService
{
    /** Relative to base_path(); gitignored. Read by vite.config.ts and merge-extension-messages.mjs. */
    public const ALLOWLIST_PATH = 'frontend/src/extensions/installed.json';

    public function __construct(private ExtensionPackageIntegrityService $integrity)
    {
    }

    /**
     * Verify every package the build will include and write the allowlist.
     *
     * A lifecycle operation writes files before it commits the package row, so
     * it says what it changed: $incoming are packages whose files it just
     * placed, checked against the verified manifest they came from rather than
     * the row (absent on an install, the previous version on an update);
     * $outgoing are packages it is removing, which the build must not include.
     *
     * @param array<string, ExtensionManifest> $incoming
     * @param list<string> $outgoing
     *
     * @return list<string> the ids the build may include
     *
     * @throws DisplayException when an included package's frontend files do not match its manifest
     * @throws \Illuminate\Database\QueryException when the package table cannot be read
     */
    public function prepare(array $incoming = [], array $outgoing = []): array
    {
        $ids = [];
        $tampered = [];

        foreach (ExtensionPackage::query()->get() as $package) {
            $id = (string) $package->extension_id;
            if (in_array($id, $outgoing, true) || array_key_exists($id, $incoming)) {
                continue;
            }

            $result = $this->integrity->inspect($package, ExtensionPackageIntegrityService::frontendFiles());

            if (!$result->valid && ($result->missingFiles !== [] || $result->modifiedFiles !== [])) {
                $tampered[$id] = [...$result->modifiedFiles, ...$result->missingFiles];

                continue;
            }

            if (!$result->valid) {
                // No authentic manifest to check the bytes against (a revoked
                // key, a corrupt retained manifest). The package cannot run
                // either; leave its frontend out rather than block every build.
                Log::warning('Extension left out of the frontend build: its manifest could not be verified.', [
                    'extension' => $id,
                    'reason' => $result->reason,
                ]);

                continue;
            }

            $ids[] = $id;
        }

        foreach ($incoming as $id => $manifest) {
            $diff = $this->integrity->compareFiles($manifest, ExtensionPackageIntegrityService::frontendFiles());

            if ($diff['missing'] !== [] || $diff['modified'] !== []) {
                $tampered[$id] = [...$diff['modified'], ...$diff['missing']];

                continue;
            }

            $ids[] = $id;
        }

        if ($tampered !== []) {
            Log::critical('Frontend build refused: extension frontend files do not match their signed manifest.', [
                'files' => $tampered,
            ]);

            $first = array_key_first($tampered);

            throw new DisplayException(sprintf('Refusing to rebuild the panel: the frontend files of extension "%s" do not match its signed manifest (%s). Repair or reinstall it first.', $first, implode(', ', array_slice($tampered[$first], 0, 3))));
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        $this->write($ids);

        return $ids;
    }

    /**
     * Atomic, so a build starting at the same moment never reads half a file.
     *
     * @param list<string> $ids
     */
    private function write(array $ids): void
    {
        $path = base_path(self::ALLOWLIST_PATH);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, json_encode(['ids' => $ids], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false
            || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new DisplayException('Unable to write the extension build allowlist at ' . self::ALLOWLIST_PATH . '. Check that the panel user can write to frontend/src/extensions.');
        }
    }
}
