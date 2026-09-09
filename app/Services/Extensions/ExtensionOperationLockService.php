<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\DisplayException;

class ExtensionOperationLockService
{
    private const LOCK_KEY = 'm12labs:extensions:operation-lock';
    private const CONTEXT_KEY = 'm12labs:extensions:operation-context';
    private const LOCK_TTL_SECONDS = 1800;

    public function __construct(
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
    }

    public function withinLock(string $action, ?string $subject, callable $callback)
    {
        $this->assertSafeExecutionUser();

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

    /**
     * Package migrations are ordinary in-process PHP. Running a lifecycle
     * command as root would therefore promote reviewed extension code—and any
     * file swapped into a web-writable package tree during an update—to root.
     * Refuse that execution model rather than trying to make a recursive
     * chown/copy/require sequence race-free.
     */
    private function assertSafeExecutionUser(): void
    {
        // The test runner in containers commonly has uid 0. Tests do not form
        // a production trust boundary and need to exercise the lifecycle.
        if (app()->environment('testing')) {
            return;
        }

        if ($this->ownershipService->isRunningAsRoot()) {
            throw new DisplayException('Extension install, update, and uninstall operations may not run as root. Run the command as the panel service account (for example, sudo -u www-data php artisan ...).');
        }
    }

    private function buildBlockedMessage(): string
    {
        $context = Cache::get(self::CONTEXT_KEY);
        $action = is_array($context) ? ($context['action'] ?? null) : null;
        $subject = is_array($context) ? trim((string) ($context['subject'] ?? '')) : '';

        if ($action === null) {
            return 'Another extension action is already running. Wait for the previous install, update, or uninstall to finish before starting a new one.';
        }

        // NOTE: Current actions (install, update, uninstall) all form regular past participles
        // with "-ed". If a new action with an irregular past tense is added, use a lookup map.
        $suffix = $subject !== '' ? sprintf(' (%s)', $subject) : '';

        return sprintf(
            'Another extension is currently being %sed%s. Wait for the previous extension action to finish before starting a new install, update, or uninstall.',
            $action,
            $suffix
        );
    }
}
