<?php

namespace Everest\Services\Extensions;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\Service\Extension\ExtensionLockLostException;

/**
 * An owner-qualified cache lock paired with a monotonically increasing fence.
 *
 * Refreshing is conditional on the cache driver's current owner. The context
 * comparison adds a generation fence so a stale process cannot continue after
 * another process has acquired the same logical lock, even if it retained an
 * old in-memory lock object.
 */
class ExtensionLockLease
{
    private bool $closed = false;

    private function __construct(
        private readonly Lock $lock,
        private readonly string $scope,
        private readonly string $contextKey,
        private readonly int $ttlSeconds,
        private readonly int $generation,
        private readonly array $context,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function acquire(
        string $lockKey,
        string $contextKey,
        string $generationKey,
        string $scope,
        int $ttlSeconds,
        array $context = [],
    ): ?self {
        $lock = Cache::lock($lockKey, $ttlSeconds);
        if (!$lock instanceof Lock || !$lock->get()) {
            return null;
        }

        try {
            // add() makes increment portable to stores that do not create a
            // missing integer key themselves. The generation intentionally
            // outlives individual leases.
            Cache::add($generationKey, 0, now()->addYears(20));
            $generation = Cache::increment($generationKey);
            if (!is_int($generation) || $generation < 1) {
                throw new \RuntimeException('Unable to allocate an extension lock fencing generation.');
            }

            $lease = new self(
                $lock,
                $scope,
                $contextKey,
                $ttlSeconds,
                $generation,
                $context,
            );

            if (!Cache::put($contextKey, $lease->storedContext(), $ttlSeconds)) {
                throw new \RuntimeException('Unable to persist extension lock ownership.');
            }

            return $lease;
        } catch (\Throwable $exception) {
            $lock->release();

            throw $exception;
        }
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * Verify both the lock owner and fence, then conditionally extend the TTL.
     * Every caller invokes this immediately before a shared-state mutation.
     */
    public function checkpoint(): void
    {
        if ($this->closed || !$this->ownsStoredContext() || !$this->lock->isOwnedByCurrentProcess()) {
            throw new ExtensionLockLostException($this->scope);
        }

        try {
            $refreshed = $this->lock->refresh($this->ttlSeconds);
        } catch (\Throwable $exception) {
            throw new ExtensionLockLostException($this->scope);
        }

        if (!$refreshed || !Cache::put($this->contextKey, $this->storedContext(), $this->ttlSeconds)) {
            throw new ExtensionLockLostException($this->scope);
        }
    }

    /** Release only this generation; a stale owner never erases its successor. */
    public function release(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->ownsStoredContext()) {
            Cache::forget($this->contextKey);
        }

        $this->lock->release();
    }

    private function ownsStoredContext(): bool
    {
        $stored = Cache::get($this->contextKey);

        return is_array($stored)
            && hash_equals((string) ($stored['owner'] ?? ''), $this->lock->owner())
            && (int) ($stored['generation'] ?? 0) === $this->generation;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedContext(): array
    {
        return array_merge($this->context, [
            'owner' => $this->lock->owner(),
            'generation' => $this->generation,
            'renewed_at' => now()->toIso8601String(),
        ]);
    }
}
