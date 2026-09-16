<?php

namespace Everest\Services\Streaming;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Everest\Exceptions\Service\Streaming\StreamUnavailableException;

/**
 * The panel's server-sent event transport.
 *
 * One place that knows how an SSE response is shaped, kept open and accounted
 * for, so the things that stream — the agent today, extension packages next —
 * differ only in what they put in the frames.
 *
 * What lives here is everything that is wrong to get wrong twice: the headers
 * that stop a proxy buffering the body into uselessness, the execution-time
 * ceiling, the keep-alive, the abort handling, and taking and giving back a
 * place in the connection accounting. What is deliberately absent is any notion
 * of what a frame means.
 */
class EventStream
{
    /** Grace on the slot TTL, so a leaked count outlives its stream only briefly. */
    private const SLOT_TTL_MARGIN_SECONDS = 60;

    public function __construct(private readonly StreamSlots $slots)
    {
    }

    /**
     * Open a stream, or refuse it.
     *
     * The slot is taken **before** the response is returned, because the point
     * of the ceiling is to answer 503 while there is still a worker free to say
     * so. Taking it inside the callback would mean the refusal itself consumed
     * the thing being rationed, and a client would read the rejection as a
     * successfully opened stream that immediately closed.
     *
     * @param callable(EventStreamWriter): void $producer
     *
     * @throws StreamUnavailableException
     */
    public function open(EventStreamLimits $limits, callable $producer): StreamedResponse
    {
        $slot = $this->slots->acquire($limits->slots, $limits->maxSeconds + self::SLOT_TTL_MARGIN_SECONDS);

        if ($slot === null) {
            throw new StreamUnavailableException('Too many live connections are open right now. Try again in a moment.', max(5, (int) ceil($limits->keepAliveSeconds)));
        }

        $deadlineAt = time() + $limits->maxSeconds;

        return new StreamedResponse(function () use ($limits, $producer, $slot, $deadlineAt): void {
            // PHP aborts the script on a failed write by default, which would
            // leave the cleanup below to a shutdown function. Taking the abort
            // in hand instead means the producer stops on its own terms and the
            // slot is given back on the ordinary path. `connection_aborted()`
            // only turns 1 after a write is attempted, which is a second reason
            // the keep-alive is not optional: it is how a silent producer finds
            // out nobody is listening.
            $previousAbort = ignore_user_abort(true);

            // FPM's request_terminate_timeout is the operator's to set and
            // cannot be raised from here; this covers max_execution_time, which
            // otherwise kills a long stream mid-frame.
            if (function_exists('set_time_limit')) {
                @set_time_limit($limits->maxSeconds + self::SLOT_TTL_MARGIN_SECONDS);
            }

            $writer = new EventStreamWriter($deadlineAt, $limits->keepAliveSeconds);

            try {
                // A reader that is already up to date would otherwise sit silent
                // until the first frame, which from the browser is
                // indistinguishable from a stream that never opened.
                $writer->comment('open');

                $producer($writer);
            } finally {
                $slot->release();
                ignore_user_abort($previousAbort);
            }
        }, 200, [
            ...$limits->headers,
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            // nginx buffers proxied responses by default, which turns an event
            // stream into one delivery at the end of it.
            'X-Accel-Buffering' => 'no',
            'X-Stream-Deadline-Seconds' => (string) $limits->maxSeconds,
            'X-Stream-Keepalive-Seconds' => (string) $limits->keepAliveSeconds,
        ]);
    }
}
