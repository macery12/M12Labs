<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Cache;

class ExtensionOperationLockService
{
    private const LOCK_KEY = 'm12labs:extensions:operation-lock';
    private const CONTEXT_KEY = 'm12labs:extensions:operation-context';
    private const LOCK_TTL_SECONDS = 1800;

    public function withinLock(string $action, ?string $subject, callable $callback)
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (!$lock->get()) {
            throw new DisplayException($this->buildBlockedMessage());
        }

        Cache::put(self::CONTEXT_KEY, [
            'action' => $action,
            'subject' => $subject,
            'started_at' => now()->toIso8601String(),
        ], self::LOCK_TTL_SECONDS);

        try {
            return $callback();
        } finally {
            Cache::forget(self::CONTEXT_KEY);
            $lock->release();
        }
    }

    private function buildBlockedMessage(): string
    {
        $context = Cache::get(self::CONTEXT_KEY);
        $subject = is_array($context) ? trim((string) ($context['subject'] ?? '')) : '';

        return match (is_array($context) ? ($context['action'] ?? null) : null) {
            'install' => $subject !== ''
                ? sprintf(
                    'Another extension is currently being installed (%s). Wait for the previous extension action to finish before starting a new install or uninstall.',
                    $subject
                )
                : 'Another extension is currently being installed. Wait for the previous extension action to finish before starting a new install or uninstall.',
            'uninstall' => $subject !== ''
                ? sprintf(
                    'Another extension is currently being uninstalled (%s). Wait for the previous extension action to finish before starting a new install or uninstall.',
                    $subject
                )
                : 'Another extension is currently being uninstalled. Wait for the previous extension action to finish before starting a new install or uninstall.',
            default => 'Another extension action is already running. Wait for the previous install or uninstall to finish before starting a new one.',
        };
    }
}