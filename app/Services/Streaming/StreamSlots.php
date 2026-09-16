<?php

namespace Everest\Services\Streaming;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * How many streams are open, and whether one more may be.
 *
 * An open stream holds a PHP-FPM child for its whole life, which is what makes
 * this necessary rather than tidy: every other surface a package contributes is
 * bounded by how long a request takes, and this one is bounded only by what the
 * manifest asked for. Without a ceiling, ordinary use of several streaming
 * packages exhausts the worker pool.
 *
 * Counters rather than a registry of live connections, because the counter is a
 * single atomic operation on the cache and a registry would need pruning on a
 * path where the pruning itself can be what runs out of workers.
 *
 * The honest failure mode: a worker killed without unwinding leaks its count
 * until the key expires. Every key is therefore written with a TTL a little
 * past the longest stream it could be holding, so a leak drains on its own
 * rather than needing an operator. An expiry under live connections undercounts
 * instead, which admits one extra stream rather than locking the feature out.
 */
class StreamSlots
{
    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Take a place against every limit, or return null if any is full.
     *
     * All-or-nothing: a caller that passed the per-user limit but not the
     * global one must not leave its per-user count raised, or a deployment
     * under load would slowly lock out the very users who kept retrying.
     *
     * @param array<string, int> $limits keyed counter name => ceiling
     */
    public function acquire(array $limits, int $holdSeconds): ?StreamSlot
    {
        $taken = [];

        foreach ($limits as $key => $limit) {
            if (!$this->take($key, $limit, $holdSeconds)) {
                $this->give($taken);

                return null;
            }

            $taken[] = $key;
        }

        $slot = new StreamSlot($taken, fn (array $keys) => $this->give($keys));

        // The `finally` around the producer is the ordinary path. This is for
        // the case PHP tears the script down on a failed write instead of
        // returning through it; releasing twice is a no-op.
        register_shutdown_function(static fn () => $slot->release());

        return $slot;
    }

    /** What is currently held against one counter. */
    public function held(string $key): int
    {
        return max(0, (int) $this->cache->get($this->key($key), 0));
    }

    private function take(string $key, int $limit, int $holdSeconds): bool
    {
        $key = $this->key($key);

        // `add` only writes when absent, so the TTL is set once per counter
        // lifetime and the increments below inherit it rather than extending
        // it — which is what bounds a leaked count to one stream's duration
        // plus the margin rather than to forever.
        $this->cache->add($key, 0, $holdSeconds);

        $count = $this->cache->increment($key);

        // A store that cannot increment atomically (or a key that expired
        // between the two calls) answers something unusable. Refusing on it
        // would make streams unavailable on a healthy deployment, so admit and
        // rely on the declared per-connection deadline instead.
        if (!is_int($count)) {
            return true;
        }

        if ($count > $limit) {
            $this->cache->decrement($key);

            return false;
        }

        return true;
    }

    /** @param array<int, string> $keys */
    private function give(array $keys): void
    {
        foreach ($keys as $key) {
            $key = $this->key($key);

            if ($this->cache->get($key) !== null) {
                $this->cache->decrement($key);
            }
        }
    }

    private function key(string $key): string
    {
        return 'stream-slots:' . $key;
    }
}
