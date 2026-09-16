<?php

namespace Everest\Services\Streaming;

/**
 * One held place in the connection accounting, released exactly once.
 *
 * Idempotent because it is released from two places that can both run: the
 * `finally` around the producer, and a shutdown function registered as a
 * backstop for the case PHP tears the script down on a failed write rather than
 * returning through the `finally`.
 */
final class StreamSlot
{
    private bool $released = false;

    /** @param array<int, string> $keys */
    public function __construct(
        private readonly array $keys,
        private readonly \Closure $onRelease,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        ($this->onRelease)($this->keys);
    }
}
