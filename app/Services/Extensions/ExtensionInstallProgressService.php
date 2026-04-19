<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks the real-time installation/uninstallation progress of an extension
 * using a short-lived Cache entry so that the frontend can poll for status.
 */
class ExtensionInstallProgressService
{
    private const PROGRESS_KEY = 'm12labs:extensions:install-progress';
    private const PROGRESS_TTL_SECONDS = 1800;

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
        Cache::put(self::PROGRESS_KEY, [
            'action' => $action,
            'extension_id' => $extensionId,
            'stage' => $stage,
            'updated_at' => now()->toIso8601String(),
        ], self::PROGRESS_TTL_SECONDS);
    }

    /**
     * Return the current progress payload or null when no operation is running.
     *
     * @return array<string, string>|null
     */
    public function current(): ?array
    {
        $value = Cache::get(self::PROGRESS_KEY);

        return is_array($value) ? $value : null;
    }

    /**
     * Clear the progress record (called after an operation finishes or fails).
     */
    public function clear(): void
    {
        Cache::forget(self::PROGRESS_KEY);
    }
}
