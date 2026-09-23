<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionQueueJob;
use Everest\Exceptions\DisplayException;
use Everest\Services\Queue\HorizonProvisioningReconciler;

/**
 * Empties an extension's queue before its files are touched.
 *
 * Removing a package's class files while a worker holds one of its jobs leaves
 * an unserializable payload: the job can neither succeed nor be retried, and
 * the failed-job row is unusable because the class it names is gone. So an
 * uninstall or update drains first.
 *
 * A drain has two halves. beginDrain() refuses new dispatches at the source and
 * makes workers discard anything already queued. Those rows remain in flight
 * until a worker has deleted the actual backend payload and acknowledged the
 * cancellation. Running jobs cannot be interrupted — PHP has no safe way to
 * preempt one — so waitForDrain() waits for both states within a budget.
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

    /**
     * The guard lifecycle operations call once the drain budget is spent.
     * Queued rows still represent real backend payloads, and running rows
     * represent code already executing. Neither is safe to carry across a file
     * replacement or removal.
     */
    public function assertSafeToRemove(string $extensionId): void
    {
        $inFlight = $this->inFlight($extensionId);

        if ($inFlight > 0) {
            $message = sprintf('The extension [%s] still has %d queued or running backend job(s). Keep the drain active and let workers delete or finish them before replacing its files.', $extensionId, $inFlight);

            // A drain is carried out by workers. On a lane with none -- which
            // is what a missed Horizon restart leaves -- nothing will ever
            // remove the job, and waiting longer cannot help. Say so.
            try {
                $missing = app(HorizonProvisioningReconciler::class)->missing();
            } catch (\Throwable) {
                $missing = [];
            }

            if ($missing !== []) {
                $message .= sprintf(' Horizon is not running %s, so no worker can; run "php artisan p:queue:reconcile --now" and retry.', implode(', ', $missing));
            }

            throw new DisplayException($message);
        }
    }
}
