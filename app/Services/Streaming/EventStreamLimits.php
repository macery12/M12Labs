<?php

namespace Everest\Services\Streaming;

/**
 * What bounds one event stream: how long it may run, how often it must speak,
 * and which counters it takes a place against.
 *
 * Deliberately not the manifest's `StreamDefinition`. Core streams the agent
 * without any package being involved, so the transport must not need a package
 * to describe a stream — the extension layer translates its declaration into
 * one of these, and nothing below here knows extensions exist.
 */
final readonly class EventStreamLimits
{
    /**
     * @param array<string, int> $slots counter name => ceiling, all of which
     *                                  must admit the connection
     */
    public function __construct(
        public int $maxSeconds,
        public int $keepAliveSeconds = 15,
        public array $slots = [],
        /** Written alongside the SSE headers, for facts a client needs up front. */
        public array $headers = [],
    ) {
    }

    public function withSlots(array $slots): self
    {
        return new self($this->maxSeconds, $this->keepAliveSeconds, $slots, $this->headers);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->maxSeconds, $this->keepAliveSeconds, $this->slots, [...$this->headers, ...$headers]);
    }
}
