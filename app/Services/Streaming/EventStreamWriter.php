<?php

namespace Everest\Services\Streaming;

/**
 * The socket end of a server-sent event stream.
 *
 * Everything that produces a stream writes through one of these rather than
 * echoing SSE inline, which is what makes the destination swappable: the same
 * producer can be handed a writer that talks to a browser or one that appends
 * to a log for a worker to replay.
 *
 * Framing, flushing and the keep-alive are the whole of what this owns. What a
 * frame *means* belongs to the producer, and deliberately never appears here.
 */
class EventStreamWriter
{
    private int $lastWriteAt;

    public function __construct(
        private readonly int $deadlineAt,
        private readonly int $keepAliveSeconds,
    ) {
        $this->lastWriteAt = time();
    }

    /**
     * One named event.
     *
     * The name travels as SSE's own `event:` field, so a consumer can dispatch
     * on it with `addEventListener` instead of parsing the payload to find out
     * what it is holding.
     */
    public function event(string $name, mixed $data, ?int $id = null): void
    {
        if ($id !== null) {
            $this->line('id: ' . $id);
        }

        $this->line('event: ' . $this->sanitize($name));
        $this->frame('data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** An unnamed frame, for a producer whose stream carries one shape. */
    public function data(mixed $data, ?int $id = null): void
    {
        if ($id !== null) {
            $this->line('id: ' . $id);
        }

        $this->frame('data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A comment. Carries no event, and exists so a proxy between here and the
     * browser sees traffic while the producer is still thinking.
     */
    public function comment(string $text = 'keep-alive'): void
    {
        $this->frame(': ' . $this->sanitize($text));
    }

    /**
     * Write a keep-alive only if the stream has been silent long enough to need
     * one. A producer calls this freely in its own loop; the interval, not the
     * call site, decides whether anything is sent.
     */
    public function tick(): void
    {
        if (time() - $this->lastWriteAt >= $this->keepAliveSeconds) {
            $this->comment();
        }
    }

    /** Seconds left before the deadline, floored at zero. */
    public function remainingSeconds(): int
    {
        return max(0, $this->deadlineAt - time());
    }

    /**
     * Whether the producer should stop.
     *
     * True when the deadline has passed or the browser has gone away. The abort
     * check is the reason a producer should consult this rather than its own
     * clock: a client that navigated away leaves a worker doing work nobody is
     * reading, and PHP only reports that after a write has been attempted.
     */
    public function shouldStop(): bool
    {
        return $this->remainingSeconds() === 0 || connection_aborted() === 1;
    }

    /** The sentinel a consumer reads as "the producer finished, deliberately". */
    public function close(): void
    {
        $this->frame('data: [DONE]');
    }

    /**
     * Strip anything that would end the frame early. A newline inside an event
     * name or a comment would otherwise let a producer's own data forge the
     * frame boundary, and the producer is package code.
     */
    private function sanitize(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }

    /** A line within a frame: no blank line, so the frame stays open. */
    protected function line(string $line): void
    {
        echo $line . "\n";
    }

    /**
     * Terminate a frame and push it out.
     *
     * `ob_flush()` emits a notice when no buffer is active, which would land as
     * garbage in the middle of the stream — hence the level check.
     */
    protected function frame(string $line): void
    {
        echo $line . "\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();

        $this->lastWriteAt = time();
    }
}
