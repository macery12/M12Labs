<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * Operational requirements for one logical group of an extension's jobs.
 *
 * The name is NOT a Redis queue. Extension work rides one of two static lanes,
 * because per-extension queues would require mutating cached Horizon supervisor
 * config at runtime. The name instead scopes the rate limiter, the concurrency
 * quota and the admin grouping.
 *
 * Which of the two lanes is the one thing here that is not merely a budget.
 * `longRunning` moves the group onto `extensions-long`, which is consumed by
 * its own supervisor on the long connection — the only place a timeout above
 * the short connection's `retry_after` is safe, and the only way a package's
 * hour-long import does not sit in front of its own thirty-second webhook.
 */
final readonly class QueueDefinition implements \JsonSerializable
{
    /** Where an extension's ordinary jobs ride. */
    public const LANE = 'extensions';

    /** Where the groups that declared `longRunning` ride. */
    public const LONG_LANE = 'extensions-long';

    public function __construct(
        public string $name,
        public int $maxAttempts = 3,
        public int $timeoutSeconds = 60,
        /**
         * Put this group on the long lane. Declared, not inferred from the
         * timeout, because it is what an administrator approves: a dedicated
         * worker held for the length of the job rather than a slot in a queue
         * that turns over.
         */
        public bool $longRunning = false,
        /** @var array<int, int> */
        public array $backoffSeconds = [10, 60, 300],
        /** Limiter budget in the form "<count>/<minute|second|hour>". */
        public ?string $rateLimit = null,
        public ?int $maxConcurrent = null,
        /** Refuse to dispatch when this many jobs are already queued/running. */
        public ?int $maxOutstanding = null,
        /** Enables overlap protection for this many seconds when set. */
        public ?int $uniqueForSeconds = null,
    ) {
    }

    /** The `config/queue.php` lane key this group's jobs are dispatched to. */
    public function lane(): string
    {
        return $this->longRunning ? self::LONG_LANE : self::LANE;
    }

    public function limiterName(string $extensionId): string
    {
        return sprintf('ext:%s:%s', $extensionId, $this->name);
    }

    /**
     * Parsed rate limit as [count, perSeconds], or null when unlimited.
     *
     * @return array{0: int, 1: int}|null
     */
    public function parsedRateLimit(): ?array
    {
        if ($this->rateLimit === null || !preg_match('#^(\d+)/(second|minute|hour)$#', $this->rateLimit, $matches)) {
            return null;
        }

        return [
            (int) $matches[1],
            match ($matches[2]) {
                'second' => 1,
                'hour' => 3600,
                default => 60,
            },
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'maxAttempts' => $this->maxAttempts,
            'timeoutSeconds' => $this->timeoutSeconds,
            // Emitted only when true, so the projection — and therefore the
            // capability hash — of every package installed before the long
            // lane existed is byte-identical to what it was. array_filter
            // below drops the null.
            'longRunning' => $this->longRunning ? true : null,
            'backoffSeconds' => $this->backoffSeconds,
            'rateLimit' => $this->rateLimit,
            'maxConcurrent' => $this->maxConcurrent,
            'maxOutstanding' => $this->maxOutstanding,
            'uniqueForSeconds' => $this->uniqueForSeconds,
        ], fn ($value) => $value !== null);
    }
}
