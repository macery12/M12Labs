<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionQueueJob;
use Illuminate\Support\Facades\Cache;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

/**
 * The panel's answer to "how may this extension's job behave?".
 *
 * Retry count, timeout, backoff, rate limit, concurrency and quota all come
 * from the verified manifest, never from the job class — a package that could
 * set its own tries and timeout could pin a worker indefinitely and retry a
 * failing external call forever. ExtensionJob reads everything through here.
 *
 * A job whose queue group is not declared has no definition, and the enabled
 * gate deletes it rather than guessing defaults: the manifest is the contract,
 * and running work nobody declared is exactly what the capability model exists
 * to prevent.
 */
class ExtensionQueueRegistry
{
    /** Cache key holding the set of extension ids currently refusing dispatch. */
    private const DRAIN_KEY = 'm12labs:extensions:draining';

    /** A drain that outlives its operation must not wedge the queue forever. */
    private const DRAIN_TTL_SECONDS = 3600;

    public function __construct(private ExtensionRuntimePlanService $plan)
    {
    }

    /**
     * The declared definition for one logical queue group, or null when the
     * extension is not runnable or never declared it.
     */
    public function definition(string $extensionId, string $queueName): ?QueueDefinition
    {
        return $this->plan->entry($extensionId)?->capabilities->queue($queueName);
    }

    /**
     * Every declared queue group across the enabled set, as
     * [extensionId, definition] pairs. Used to register limiters at boot.
     *
     * @return array<int, array{id: string, queue: QueueDefinition}>
     */
    public function all(): array
    {
        $queues = [];

        foreach ($this->plan->withCapability('queues') as $entry) {
            foreach ($entry->capabilities->queues as $queue) {
                $queues[] = ['id' => $entry->id, 'queue' => $queue];
            }
        }

        return $queues;
    }

    /**
     * Jobs already queued or running for a group. This is the quota input, and
     * it is deliberately a live count rather than a counter: a counter drifts
     * whenever a worker is killed, and drifts in the direction that refuses
     * legitimate work forever.
     */
    public function outstanding(string $extensionId, string $queueName): int
    {
        return ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->where('queue_name', $queueName)
            ->whereIn('status', ExtensionQueueJob::IN_FLIGHT)
            ->count();
    }

    public function isDraining(string $extensionId): bool
    {
        return in_array($extensionId, $this->draining(), true);
    }

    /**
     * Refuse further dispatches for an extension. Set before an uninstall or
     * update touches a single file, so no new work can enter while the
     * in-flight set is being emptied.
     */
    public function beginDrain(string $extensionId): void
    {
        $draining = $this->draining();

        if (!in_array($extensionId, $draining, true)) {
            $draining[] = $extensionId;
        }

        Cache::put(self::DRAIN_KEY, $draining, self::DRAIN_TTL_SECONDS);
    }

    public function endDrain(string $extensionId): void
    {
        $draining = array_values(array_diff($this->draining(), [$extensionId]));

        $draining === []
            ? Cache::forget(self::DRAIN_KEY)
            : Cache::put(self::DRAIN_KEY, $draining, self::DRAIN_TTL_SECONDS);
    }

    /**
     * @return array<int, string>
     */
    private function draining(): array
    {
        $draining = Cache::get(self::DRAIN_KEY, []);

        return is_array($draining) ? array_values(array_filter($draining, 'is_string')) : [];
    }
}
