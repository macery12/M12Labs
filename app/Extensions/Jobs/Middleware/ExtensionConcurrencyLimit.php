<?php

namespace Everest\Extensions\Jobs\Middleware;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Caps how many of one extension queue group's jobs run at once.
 *
 * A rate limit bounds starts per interval, not simultaneous work: an extension
 * calling a provider that allows three concurrent connections is not protected
 * by "20 per minute" if all twenty are slow. This holds one of N named slot
 * locks for the duration of the job and releases the job back to the queue when
 * every slot is taken.
 *
 * Locks carry a TTL of the job's own timeout plus a margin, so a worker killed
 * mid-job frees its slot rather than starving the group until a redeploy.
 */
class ExtensionConcurrencyLimit
{
    public function __construct(
        private string $key,
        private int $maxConcurrent,
        private int $timeoutSeconds,
        /** How long a job waits on the queue before trying for a slot again. */
        private int $releaseAfterSeconds = 15,
    ) {
    }

    public function handle(object $job, \Closure $next): mixed
    {
        $lock = $this->acquire();

        if ($lock === null) {
            if (method_exists($job, 'release')) {
                $job->release($this->releaseAfterSeconds);
            }

            return null;
        }

        try {
            return $next($job);
        } finally {
            $lock->release();
        }
    }

    private function acquire(): ?Lock
    {
        // A margin over the job timeout: the worker kills the job at its
        // timeout, and the slot must outlive that rather than expiring under a
        // job that is still running.
        $ttl = $this->timeoutSeconds + 30;

        for ($slot = 0; $slot < $this->maxConcurrent; ++$slot) {
            $lock = Cache::lock(sprintf('%s:slot:%d', $this->key, $slot), $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
