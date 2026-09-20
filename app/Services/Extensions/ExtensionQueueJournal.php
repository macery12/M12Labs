<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Everest\Models\ExtensionQueueJob;
use Illuminate\Queue\Events\JobFailed;
use Everest\Exceptions\DisplayException;
use Illuminate\Queue\Events\JobQueueing;
use Everest\Extensions\Jobs\ExtensionJob;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Keeps extension_queue_jobs in step with what the queue is actually doing.
 *
 * Everything here hangs off the framework's own queue events rather than off
 * hooks inside ExtensionJob, so a package cannot opt out of being recorded by
 * overriding a method — and cannot dispatch work that an uninstall would then
 * fail to notice.
 *
 * JobQueueing is also where the maxOutstanding quota, drain refusal and
 * durable reservation are enforced. It is the last point before the backend
 * push: if the journal cannot reserve the payload there, dispatch fails closed
 * and no untracked package job reaches Redis or the database queue.
 */
class ExtensionQueueJournal
{
    public function __construct(private ExtensionQueueRegistry $registry)
    {
    }

    /**
     * Refuse a dispatch that must not happen. Throwing here surfaces at the
     * call site, which is what an extension's own code should see.
     */
    public function queueing(JobQueueing $event): void
    {
        $job = $event->job;

        if (!$job instanceof ExtensionJob) {
            return;
        }

        $id = $job->extensionId();
        $group = $job->queueGroup();

        if ($this->registry->isDraining($id)) {
            throw new DisplayException(sprintf('The extension [%s] is being drained for a lifecycle operation and is not accepting new jobs.', $id));
        }

        $definition = $this->registry->definition($id, $group);

        if ($definition === null) {
            throw new DisplayException(sprintf('The extension [%s] did not declare the queue group [%s].', $id, $group));
        }

        if ($definition->maxOutstanding !== null
            && $this->registry->outstanding($id, $group) >= $definition->maxOutstanding) {
            throw new DisplayException(sprintf('The queue group [%s] for extension [%s] already has its maximum of %d jobs in flight.', $group, $id, $definition->maxOutstanding));
        }

        $uuid = $event->payload()['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '') {
            throw new DisplayException(sprintf('The queue backend did not assign a trackable UUID to the extension [%s] job.', $id));
        }

        // Deliberately not wrapped by record(). This write reserves the exact
        // payload before the queue driver sees it. Failing open here creates a
        // backend message no lifecycle operation can know it must drain.
        ExtensionQueueJob::query()->create([
            'job_uuid' => $uuid,
            'extension_id' => $id,
            'queue_name' => $group,
            'job_class' => $job::class,
            'status' => ExtensionQueueJob::STATUS_QUEUED,
            'dispatched_at' => now(),
        ]);
    }

    public function processing(JobProcessing $event): void
    {
        $this->transition($event->job->uuid(), [
            'status' => ExtensionQueueJob::STATUS_RUNNING,
            'attempts' => $event->job->attempts(),
            'started_at' => now(),
        ]);
    }

    public function processed(JobProcessed $event): void
    {
        // A released job is not finished; the worker will hand it back.
        if ($event->job->isReleased()) {
            $this->transition($event->job->uuid(), [
                'status' => ExtensionQueueJob::STATUS_QUEUED,
                'started_at' => null,
            ]);

            return;
        }

        if ($event->job->isDeleted()) {
            // Deleted without failing: the enabled gate cancelled it and has
            // already written the reason.
            return;
        }

        $this->transition($event->job->uuid(), [
            'status' => ExtensionQueueJob::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
    }

    public function failed(JobFailed $event): void
    {
        $this->transition($event->job->uuid(), [
            'status' => ExtensionQueueJob::STATUS_FAILED,
            'attempts' => $event->job->attempts(),
            'finished_at' => now(),
            // Class and message only. A job's payload can carry an extension's
            // secrets and this row is rendered on an admin page.
            'last_error' => sprintf('%s: %s', $event->exception::class, Str::limit($event->exception->getMessage(), 500)),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function transition(string $uuid, array $attributes): void
    {
        $this->record(fn () => ExtensionQueueJob::query()
            ->where('job_uuid', $uuid)
            ->update($attributes + ['updated_at' => now()]));
    }

    /**
     * Terminal bookkeeping failures leave the row in flight. That can refuse a
     * lifecycle operation until an operator repairs the journal, but it cannot
     * permit package files to be replaced while a payload might still exist.
     */
    private function record(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            Log::warning('Extension queue bookkeeping failed.', ['exception' => $exception->getMessage()]);
        }
    }
}
