<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionQueueJob;
use Everest\Exceptions\DisplayException;

/**
 * Empties an extension's queue before its files are touched.
 *
 * Removing a package's class files while a worker holds one of its jobs leaves
 * an unserializable payload: the job can neither succeed nor be retried, and
 * the failed-job row is unusable because the class it names is gone. So an
 * uninstall or update drains first.
 *
 * A drain has two halves. beginDrain() refuses new dispatches at the source and
 * makes the enabled gate discard anything already queued, which empties the
 * queued set quickly. Running jobs cannot be interrupted — PHP has no safe way
 * to preempt one — so waitForDrain() waits for them within a budget, and what
 * happens on timeout is the operator's decision, not this service's.
 */
class ExtensionJobDrainService
{
    public function __construct(private ExtensionQueueRegistry $registry)
    {
    }

    public function beginDrain(string $extensionId): void
    {
        $this->registry->beginDrain($extensionId);
    }

    public function endDrain(string $extensionId): void
    {
        $this->registry->endDrain($extensionId);
    }

    /**
     * Wait until nothing is queued or running for this extension.
     *
     * @return bool true when the queue emptied within the budget
     */
    public function waitForDrain(string $extensionId, int $timeoutSeconds = 60, int $pollMilliseconds = 500): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if ($this->inFlight($extensionId) === 0) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep($pollMilliseconds * 1000);
        }
    }

    public function inFlight(string $extensionId): int
    {
        return ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->whereIn('status', ExtensionQueueJob::IN_FLIGHT)
            ->count();
    }

    public function running(string $extensionId): int
    {
        return ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->where('status', ExtensionQueueJob::STATUS_RUNNING)
            ->count();
    }

    /**
     * Discard everything still queued. Jobs already running are left alone —
     * they are executing package code that this process cannot stop.
     */
    public function cancelQueued(string $extensionId): int
    {
        return ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->where('status', ExtensionQueueJob::STATUS_QUEUED)
            ->update([
                'status' => ExtensionQueueJob::STATUS_CANCELLED,
                'last_error' => 'Cancelled while draining for a lifecycle operation.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Set aside jobs that outlasted the drain budget so the operation can
     * proceed. Quarantined rows are excluded from the in-flight set and stay as
     * a record that work was abandoned mid-flight, which is not the same thing
     * as work that completed.
     */
    public function quarantine(string $extensionId): int
    {
        return ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->whereIn('status', ExtensionQueueJob::IN_FLIGHT)
            ->update([
                'status' => ExtensionQueueJob::STATUS_QUARANTINED,
                'last_error' => 'Still in flight when the extension was removed.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * The guard an uninstall calls once the drain budget is spent. Refuses
     * while package code is genuinely executing, because deleting its files
     * underneath a running worker is the failure this service exists to avoid.
     */
    public function assertSafeToRemove(string $extensionId): void
    {
        $running = $this->running($extensionId);

        if ($running > 0) {
            throw new DisplayException(sprintf('The extension [%s] still has %d job(s) running. Wait for them to finish, or quarantine them, before removing its files.', $extensionId, $running));
        }
    }
}
