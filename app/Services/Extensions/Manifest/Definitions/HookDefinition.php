<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * A subscription to one curated core event.
 *
 * The handler names a class inside the package's Hooks namespace; the
 * dispatcher resolves nothing that is not declared here and present on disk.
 */
final readonly class HookDefinition implements \JsonSerializable
{
    public function __construct(
        public string $event,
        public string $handler,
        public string $mode,
        /**
         * Latency budget for a synchronous handler. PHP cannot preempt a
         * handler, so exceeding it is recorded and counts toward the circuit
         * breaker rather than aborting the call.
         */
        public int $timeoutMs = 3000,
    ) {
    }

    public function isQueued(): bool
    {
        return $this->mode === 'queued_at_least_once';
    }

    public function handlerClass(string $extensionId): string
    {
        return sprintf('Everest\\Extensions\\Packages\\%s\\Hooks\\%s', $extensionId, $this->handler);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'event' => $this->event,
            'handler' => $this->handler,
            'mode' => $this->mode,
            'timeoutMs' => $this->timeoutMs,
        ];
    }
}
