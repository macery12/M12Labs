<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionPackageFile;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\FrontendSlotDefinition;

/**
 * Writes the verified frontend manifest the build reads.
 *
 * Vite globs the filesystem and the database does not exist at build time, so
 * the declared pages have to reach the bundle as a file. That file cannot be
 * one the package ships: it decides nav category, permission and label for
 * every page, which is exactly the set of claims the manifest parser exists to
 * verify. It now also carries named layout-slot declarations; the historical
 * filename remains so install/update/uninstall keep one generated-file
 * lifecycle. The panel writes it, from the already-verified manifest, into the
 * package's own frontend directory — and records it in extension_package_files
 * as `generated`, so uninstall removes it like anything else.
 *
 * A missing file means zero pages and zero slots. That is the fail-closed
 * direction: a package whose generated manifest was lost contributes no UI
 * rather than falling back to guessing from whatever .tsx files happen to be
 * on disk.
 */
class ExtensionPageManifestService
{
    public const FILENAME = 'extension.pages.json';

    /**
     * Relative path of the generated file for an extension.
     */
    public function relativePath(string $extensionId): string
    {
        return sprintf('frontend/src/extensions/packages/%s/%s', $extensionId, self::FILENAME);
    }

    /**
     * Write the file and return a file plan entry for it, in the same shape
     * prepareFilePlans() produces so the install path treats it identically.
     *
     * @return array<string, mixed>
     */
    public function write(
        ExtensionManifest $manifest,
        string $backupRoot,
        ?ExtensionPackageFile $previousFile = null,
    ): array {
        $path = $this->relativePath($manifest->id);
        $targetPath = base_path($path);

        $contents = json_encode(
            $this->payload($manifest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";

        $operation = 'generated';
        $backupPath = null;
        $backupChecksum = null;

        if ($previousFile?->operation === 'updated') {
            // A prior install replaced an untracked file here. Keep its
            // original backup across updates so a future uninstall can still
            // restore what existed before the extension was installed.
            $operation = 'updated';
            $backupPath = $previousFile->backup_path;
            $backupChecksum = $previousFile->backup_checksum;
        } elseif ($previousFile === null && is_file($targetPath)) {
            // First install into a pre-existing, untracked target. Snapshot it
            // before generating our file so a failed install and a later
            // uninstall both restore the original bytes.
            $operation = 'updated';
            $backupPath = $backupRoot . '/' . $path;
            File::ensureDirectoryExists(dirname($backupPath));
            File::copy($targetPath, $backupPath);
            $backupChecksum = hash_file('sha256', $backupPath);
        }

        File::ensureDirectoryExists(dirname($targetPath));
        File::put($targetPath, $contents);

        return [
            'path' => $path,
            'sourcePath' => null,
            'targetPath' => $targetPath,
            'operation' => $operation,
            'checksum' => hash('sha256', $contents),
            'backupPath' => $backupPath,
            'backupChecksum' => $backupChecksum,
        ];
    }

    public function remove(string $extensionId): void
    {
        $targetPath = base_path($this->relativePath($extensionId));

        if (is_file($targetPath)) {
            File::delete($targetPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ExtensionManifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'version' => $manifest->version,
            'icon' => $manifest->icon,
            'server' => array_map(
                fn (PageDefinition $page): array => $this->page($manifest->id, $page),
                $manifest->capabilities->serverPages
            ),
            'admin' => array_map(
                fn (PageDefinition $page): array => $this->page($manifest->id, $page),
                $manifest->capabilities->adminPages
            ),
            'slots' => array_map(
                fn (FrontendSlotDefinition $slot): array => $slot->jsonSerialize(),
                $manifest->capabilities->slots
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $extensionId, PageDefinition $page): array
    {
        return array_filter([
            'slug' => $page->slug,
            'labelKey' => $page->labelKey,
            'icon' => $page->icon,
            'category' => $page->category,
            'order' => $page->order,
            'requiredServerPermission' => $page->requiredServerPermission,
            'requiredFlags' => $page->requiredFlags,
            // Expanded to the full capability identifier here, not in the
            // browser: deriving a permission name client-side from a
            // package-supplied fragment is how a package ends up influencing
            // which permission is checked.
            'requiredPermission' => $page->requiredExtensionPermission === null
                ? null
                : sprintf('ext.%s.admin.%s', $extensionId, $page->requiredExtensionPermission),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
