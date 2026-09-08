<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionPackageFile;
use Everest\Exceptions\Service\Extension\ModifiedFilesRequireAcknowledgementException;

/**
 * Shared file-level helpers used by the install, update and uninstall services:
 *   – validate that tracked files haven't been modified since installation
 *   – snapshot a set of files to a temporary directory
 *   – restore a set of files from a snapshot
 */
class ExtensionPackageFileService
{
    public function __construct(
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
    }

    /**
     * Throw if any tracked file has been externally modified since installation.
     *
     * The check exists so the panel never silently discards an operator's local
     * edits, and so tampering is visible. It is not a security boundary — the
     * files are already on disk and already executing — which is why an
     * explicit acknowledgement is allowed to proceed past it.
     *
     * Legitimate drift happens: a code formatter run over the panel tree will
     * rewrite installed package PHP, and until this branch the repository's own
     * php-cs-fixer configuration did exactly that. Without an escape hatch such
     * a package can be neither updated nor removed, which is a worse outcome
     * than the one the check protects against.
     *
     * @param array<int, ExtensionPackageFile> $files
     * @param string $verb Human-readable operation verb for the error message (e.g. 'uninstalled', 'updated').
     * @param bool $acknowledgeModified Proceed anyway, discarding local edits
     *
     * @return array<int, string> the paths that differ, empty when nothing drifted
     *
     * @throws ModifiedFilesRequireAcknowledgementException
     */
    public function assertFilesUnmodified(array $files, string $verb, bool $acknowledgeModified = false, string $extensionId = ''): array
    {
        $modified = $this->modifiedPaths($files);

        if ($modified === [] || $acknowledgeModified) {
            return $modified;
        }

        throw new ModifiedFilesRequireAcknowledgementException($extensionId, $verb, $modified);
    }

    /**
     * Tracked files whose contents no longer match what was installed. A file
     * that has been deleted counts as modified: it cannot be restored on
     * rollback either.
     *
     * @param array<int, ExtensionPackageFile> $files
     *
     * @return array<int, string>
     */
    public function modifiedPaths(array $files): array
    {
        $modified = [];

        foreach ($files as $file) {
            $targetPath = base_path($file->path);

            if (!is_file($targetPath) || hash_file('sha256', $targetPath) !== $file->installed_checksum) {
                $modified[] = $file->path;
            }
        }

        return $modified;
    }

    /**
     * Copy all currently-present tracked files into $rollbackRoot, preserving relative paths.
     *
     * @param array<int, ExtensionPackageFile> $files
     */
    public function createRollbackSnapshot(array $files, string $rollbackRoot): void
    {
        foreach ($files as $file) {
            $targetPath = base_path($file->path);
            if (!is_file($targetPath)) {
                continue;
            }

            $rollbackPath = $rollbackRoot . '/' . $file->path;
            File::ensureDirectoryExists(dirname($rollbackPath));
            File::copy($targetPath, $rollbackPath);
        }
    }

    /**
     * Restore tracked files from a snapshot directory created by createRollbackSnapshot().
     *
     * @param array<int, ExtensionPackageFile> $files
     */
    public function restoreRollbackSnapshot(array $files, string $rollbackRoot): void
    {
        foreach ($files as $file) {
            $rollbackPath = $rollbackRoot . '/' . $file->path;
            if (!is_file($rollbackPath)) {
                continue;
            }

            $targetPath = base_path($file->path);
            $this->ownershipService->ensureWritablePath($targetPath, $file->path);
            File::ensureDirectoryExists(dirname($targetPath));
            File::copy($rollbackPath, $targetPath);
        }
    }
}
