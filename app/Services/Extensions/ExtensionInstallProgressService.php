<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\File;

/**
 * Tracks the real-time installation/uninstallation progress of an extension
 * using a JSON file in storage so that the progress survives cache clears
 * (e.g. the `php artisan optimize:clear` step inside the rebuild pipeline).
 */
class ExtensionInstallProgressService
{
    private const PROGRESS_FILE = 'extensions/.progress.json';

    /**
     * Valid stage identifiers emitted during an install.
     */
    public const INSTALL_STAGES = [
        'downloading',
        'extracting',
        'validating',
        'copying',
        'optimizing',
        'building',
        'registering',
        'completed',
    ];

    /**
     * Valid stage identifiers emitted during an uninstall.
     */
    public const UNINSTALL_STAGES = [
        'validating',
        'removing',
        'optimizing',
        'building',
        'registering',
        'completed',
    ];

    /**
     * Record the current stage of an in-progress operation.
     */
    public function report(string $action, string $extensionId, string $stage): void
    {
        $path = storage_path('app/' . self::PROGRESS_FILE);

        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'action' => $action,
            'extension_id' => $extensionId,
            'stage' => $stage,
            'updated_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Return the current progress payload or null when no operation is running.
     *
     * @return array<string, string>|null
     */
    public function current(): ?array
    {
        $path = storage_path('app/' . self::PROGRESS_FILE);

        if (!File::exists($path)) {
            return null;
        }

        $contents = File::get($path);
        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Clear the progress record (called after an operation finishes or fails).
     */
    public function clear(): void
    {
        $path = storage_path('app/' . self::PROGRESS_FILE);

        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
