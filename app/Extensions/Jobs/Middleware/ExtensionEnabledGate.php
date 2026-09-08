<?php

namespace Everest\Extensions\Jobs\Middleware;

use Everest\Models\ExtensionQueueJob;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;

/**
 * Stops an extension job that should no longer run.
 *
 * A queued job outlives the state it was dispatched under. Between dispatch and
 * execution the extension can be disabled, updated to a version that no longer
 * declares the queue group, quarantined, or put into a drain ahead of an
 * uninstall. In every one of those cases the job is discarded rather than
 * released: releasing would put it back on a queue nobody intends to drain, and
 * the payload references classes that may be about to be deleted from disk.
 *
 * The drain case is the exception — those jobs are discarded too, but the drain
 * is short-lived and deliberate, which is what makes an uninstall able to reach
 * a genuinely empty in-flight set instead of racing the worker forever.
 */
class ExtensionEnabledGate
{
    public function __construct(
        private string $extensionId,
        private string $queueGroup,
    ) {
    }

    public function handle(object $job, \Closure $next): mixed
    {
        $registry = app(ExtensionQueueRegistry::class);
        $plan = app(ExtensionRuntimePlanService::class);

        $reason = match (true) {
            $registry->isDraining($this->extensionId) => 'The extension is draining for a lifecycle operation.',
            $plan->entry($this->extensionId) === null => 'The extension is no longer enabled.',
            $registry->definition($this->extensionId, $this->queueGroup) === null => sprintf('The extension no longer declares the queue group [%s].', $this->queueGroup),
            default => null,
        };

        if ($reason === null) {
            return $next($job);
        }

        $this->cancel($job, $reason);

        return null;
    }

    private function cancel(object $job, string $reason): void
    {
        // InteractsWithQueue supplies $job->job while a worker is running it.
        // It is absent under Bus::fake() and on a sync dispatch, where there is
        // no queued instance to delete and no row to close out.
        $queued = property_exists($job, 'job') ? $job->job : null;

        if ($queued === null) {
            return;
        }

        $uuid = $queued->uuid();
        $queued->delete();

        ExtensionQueueJob::query()
            ->where('job_uuid', $uuid)
            ->update([
                'status' => ExtensionQueueJob::STATUS_CANCELLED,
                'last_error' => $reason,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
