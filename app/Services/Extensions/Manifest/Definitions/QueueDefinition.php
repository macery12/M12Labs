<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * Operational requirements for one logical group of an extension's jobs.
 *
 * The name is NOT a Redis queue. Extension work all rides the single static
 * `extensions` lane, because per-extension queues would require mutating cached
 * Horizon supervisor config at runtime. The name instead scopes the rate
 * limiter, the concurrency quota and the admin grouping.
 */
final readonly class QueueDefinition implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public int $maxAttempts = 3,
        public int $timeoutSeconds = 60,
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
            'backoffSeconds' => $this->backoffSeconds,
            'rateLimit' => $this->rateLimit,
            'maxConcurrent' => $this->maxConcurrent,
            'maxOutstanding' => $this->maxOutstanding,
            'uniqueForSeconds' => $this->uniqueForSeconds,
        ], fn ($value) => $value !== null);
    }
}
