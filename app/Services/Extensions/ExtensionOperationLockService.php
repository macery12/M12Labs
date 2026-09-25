<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\DisplayException;

class ExtensionOperationLockService
{
    private const LOCK_KEY = 'm12labs:extensions:operation-lock';
    private const CONTEXT_KEY = 'm12labs:extensions:operation-context';
    private const GENERATION_KEY = 'm12labs:extensions:operation-generation';

    private ?ExtensionLockLease $activeLease = null;

    public function __construct(
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
    }

    public function withinLock(string $action, ?string $subject, callable $callback)
    {
        $this->assertSafeExecutionUser();

        $lease = ExtensionLockLease::acquire(
            self::LOCK_KEY,
            self::CONTEXT_KEY,
            self::GENERATION_KEY,
            'lifecycle operation',
            $this->ttlSeconds(),
            [
                'action' => $action,
                'subject' => $subject,
                'started_at' => now()->toIso8601String(),
            ],
        );

        if ($lease === null) {
            throw new DisplayException($this->buildBlockedMessage());
        }

        $this->activeLease = $lease;

        try {
            $lease->checkpoint();

            return $callback();
        } finally {
            $this->activeLease = null;
            $lease->release();
        }
    }

    /**
     * Fence a lifecycle mutation and renew the lease while work remains active.
     * A no-op outside the public lifecycle entry points keeps read-only helper
     * use and focused service tests possible without manufacturing a lock.
     */
    public function checkpoint(): void
    {
        $this->activeLease?->checkpoint();
    }

    public function activeGeneration(): ?int
    {
        return $this->activeLease?->generation();
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('extensions.lifecycle.lock_ttl_seconds', 7200));
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
