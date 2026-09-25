<?php

namespace Everest\Extensions\Jobs;

use Everest\Models\ExtensionHookTombstone;
use Everest\Services\Extensions\ExtensionHookDispatcher;
use Everest\Services\Extensions\ExtensionRuntimePlanService;

/**
 * Runs one queued_at_least_once hook handler.
 *
 * Core-owned rather than package-owned, which is what lets a package subscribe
 * to an event asynchronously without shipping a job class, being routed
 * anywhere, or declaring a queue group of its own.
 *
 * At-least-once, as the mode name says: the queue may deliver twice and a
 * handler has to tolerate it. The alternative — exactly-once — would need a
 * transaction spanning core's database and whatever the handler talks to,
 * which does not exist.
 */
class RunExtensionHookJob extends ExtensionJob
{
    /**
     * The queue group every hook job rides, reserved by the panel. A package
     * never declares it, so its budget is the fallback rather than something a
     * manifest can widen.
     */
    public const QUEUE_GROUP = '__hooks';

    /**
     * @param array<string, mixed> $envelope
     */
    public function __construct(
        private string $hookExtensionId,
        private string $event,
        private string $handler,
        private array $envelope,
    ) {
        parent::__construct();
    }

    public function queueGroup(): string
    {
        return self::QUEUE_GROUP;
    }

    /**
     * This job is core's, not the package's: the namespace says
     * Everest\Extensions\Jobs while the work belongs to the extension named at
     * construction. Everything downstream — the enabled gate, the quota, the
     * drain — must see the owning extension.
     */
    protected function ownerExtensionId(): string
    {
        return $this->hookExtensionId;
    }

    public function handle(ExtensionHookDispatcher $dispatcher, ExtensionRuntimePlanService $plan): void
    {
        $hook = null;

        foreach ($plan->hooksFor($this->event) as $subscription) {
            if ($subscription['id'] === $this->hookExtensionId && $subscription['hook']->handler === $this->handler) {
                $hook = $subscription['hook'];
                break;
            }
        }

        // The extension was disabled, updated or removed between dispatch and
        // execution. Nothing to run, and nothing to record against a
        // subscription that no longer exists.
        if ($hook === null) {
            return;
        }

        $instance = $dispatcher->resolve($this->hookExtensionId, $hook);

        if ($instance === null) {
            return;
        }

        $started = hrtime(true);

        try {
            $instance->handle($this->envelope);
            $dispatcher->recordSuccess($this->hookExtensionId, $hook, $this->elapsedMs($started));
            $this->consumeTombstone();
        } catch (\Throwable $exception) {
            $dispatcher->recordFailure($this->hookExtensionId, $hook, $exception, $this->elapsedMs($started));

            // Rethrown so the queue's own retry and failure handling applies;
            // the health row above is the panel's record either way.
            throw $exception;
        }
    }

    /**
     * Mark the pre-delete tombstone this run consumed. Left in place on
     * failure so a retry — or an operator — can still see what was owed.
     */
    private function consumeTombstone(): void
    {
        $correlationId = $this->envelope['correlationId'] ?? null;

        if (!is_string($correlationId)) {
            return;
        }

        ExtensionHookTombstone::query()
            ->where('correlation_id', $correlationId)
            ->where('extension_id', $this->hookExtensionId)
            ->where('handler', $this->handler)
            ->update(['consumed_at' => now(), 'updated_at' => now()]);
    }

    private function elapsedMs(int $startedAtHrTime): int
    {
        return (int) round((hrtime(true) - $startedAtHrTime) / 1_000_000);
    }
}
