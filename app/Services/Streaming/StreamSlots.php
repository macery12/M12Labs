<?php

namespace Everest\Services\Streaming;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Which streams own a place, and whether one more may be admitted.
 *
 * An open stream holds a PHP-FPM child for its whole life, which is what makes
 * this necessary rather than tidy: every other surface a package contributes is
 * bounded by how long a request takes, and this one is bounded only by what the
 * manifest asked for. Without a ceiling, ordinary use of several streaming
 * packages exhausts the worker pool.
 *
 * Each stream is represented by an owner token with its own expiry. Admission,
 * pruning, and release mutate one registry under a distributed cache lock. An
 * old stream can therefore release only its own lease, even after its lease has
 * expired and the capacity has been taken by a newer connection.
 *
 * The honest failure mode: a worker killed without unwinding leaves its lease
 * until that lease's own expiry. A newer connection extends the registry cache
 * lifetime to its own deadline, so it never disappears merely because the first
 * connection in a generation reached its deadline.
 */
class StreamSlots
{
    private const REGISTRY_KEY = 'stream-slots:lease-registry:v1';

    private const REGISTRY_LOCK_KEY = 'stream-slots:lease-registry:v1:lock';

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Take a place against every limit, or return null if any is full.
     *
     * The registry is updated once, under one lock, so passing one limit and
     * failing another cannot leave a partial lease behind.
     *
     * @param array<string, int> $limits keyed counter name => ceiling
     */
    public function acquire(array $limits, int $holdSeconds): ?StreamSlot
    {
        $ownerToken = bin2hex(random_bytes(16));
        $holdSeconds = max(1, $holdSeconds);

        try {
            $acquired = $this->withRegistry(function (array &$registry, int $now) use ($limits, $holdSeconds, $ownerToken): bool {
                foreach ($limits as $key => $limit) {
                    if ($limit < 1 || count($registry[$key] ?? []) >= $limit) {
                        return false;
                    }
                }

                $expiresAt = $now + $holdSeconds;

                foreach (array_keys($limits) as $key) {
                    $registry[$key][$ownerToken] = $expiresAt;
                }

                return true;
            });
        } catch (LockTimeoutException) {
            // Contention must fail closed: admitting without serialising this
            // read-modify-write would make the ceilings advisory under load.
            return null;
        }

        if (!$acquired) {
            return null;
        }

        $keys = array_keys($limits);
        $slot = new StreamSlot($keys, $ownerToken, fn (array $ownedKeys, string $ownedToken) => $this->give($ownedKeys, $ownedToken));

        // The `finally` around the producer is the ordinary path. This is for
        // the case PHP tears the script down on a failed write instead of
        // returning through it; releasing twice is a no-op.
        register_shutdown_function(static fn () => $slot->release());

        return $slot;
    }

    /** What is currently held against one counter. */
    public function held(string $key): int
    {
        return $this->withRegistry(
            static fn (array &$registry): int => count($registry[$key] ?? [])
        );
    }

    /** @param array<int, string> $keys */
    private function give(array $keys, string $ownerToken): void
    {
        try {
            $this->withRegistry(static function (array &$registry) use ($keys, $ownerToken): void {
                foreach ($keys as $key) {
                    unset($registry[$key][$ownerToken]);

                    if (($registry[$key] ?? []) === []) {
                        unset($registry[$key]);
                    }
                }
            });
        } catch (LockTimeoutException) {
            // A failed cleanup retains capacity until this lease expires. It
            // must never remove another owner's lease or bypass admission.
        }
    }

    /**
     * Prune and mutate the registry while holding its distributed lock.
     *
     * @template TResult
     *
     * @param \Closure(array<string, array<string, int>>&, int): TResult $mutate
     *
     * @return TResult
     */
    private function withRegistry(\Closure $mutate): mixed
    {
        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            throw new \LogicException('The stream slot cache store must support atomic locks.');
        }

        return $store->lock(self::REGISTRY_LOCK_KEY, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function () use ($mutate): mixed {
                $now = $this->now();
                $registry = $this->prune($this->registry(), $now);
                $result = $mutate($registry, $now);

                $this->store($registry, $now);

                return $result;
            }
        );
    }

    /** @return array<string, array<string, int>> */
    private function registry(): array
    {
        $stored = $this->cache->get(self::REGISTRY_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $registry = [];

        foreach ($stored as $key => $leases) {
            if (!is_string($key) || !is_array($leases)) {
                continue;
            }

            foreach ($leases as $ownerToken => $expiresAt) {
                if (is_string($ownerToken) && is_int($expiresAt)) {
                    $registry[$key][$ownerToken] = $expiresAt;
                }
            }
        }

        return $registry;
    }

    /**
     * @param array<string, array<string, int>> $registry
     *
     * @return array<string, array<string, int>>
     */
    private function prune(array $registry, int $now): array
    {
        foreach ($registry as $key => $leases) {
            foreach ($leases as $ownerToken => $expiresAt) {
                if ($expiresAt <= $now) {
                    unset($registry[$key][$ownerToken]);
                }
            }

            if ($registry[$key] === []) {
                unset($registry[$key]);
            }
        }

        return $registry;
    }

    /** @param array<string, array<string, int>> $registry */
    private function store(array $registry, int $now): void
    {
        if ($registry === []) {
            $this->cache->forget(self::REGISTRY_KEY);

            return;
        }

        $latestExpiry = $now + 1;

        foreach ($registry as $leases) {
            $latestExpiry = max($latestExpiry, ...array_values($leases));
        }

        if (!$this->cache->put(self::REGISTRY_KEY, $registry, max(1, $latestExpiry - $now))) {
            throw new \RuntimeException('The stream lease registry could not be persisted.');
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
