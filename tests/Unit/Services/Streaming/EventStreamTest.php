<?php

namespace Everest\Tests\Unit\Services\Streaming;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Everest\Services\Streaming\EventStream;
use Everest\Services\Streaming\StreamSlots;
use Everest\Services\Streaming\EventStreamLimits;
use Everest\Services\Streaming\EventStreamWriter;
use Everest\Exceptions\Service\Streaming\StreamUnavailableException;

/**
 * The panel's server-sent event transport.
 *
 * Two things are under test and they fail in opposite directions. The writer's
 * framing is a wire format: get it wrong and a browser silently sees nothing,
 * or sees a producer's own text as frame boundaries. The accounting is a
 * resource limit: get it wrong and a deployment either refuses streams it could
 * serve, or holds more PHP workers than it has.
 */
class EventStreamTest extends TestCase
{
    private function slots(): StreamSlots
    {
        return new StreamSlots(Cache::store('array'));
    }

    /**
     * Run a producer and return exactly what went to the socket.
     *
     * Two buffers, because the writer flushes its own: a single `ob_start()`
     * would be the thing it flushed *out of*, and the capture would come back
     * empty while the frames went to stdout.
     */
    private function capture(callable $producer, int $maxSeconds = 60, int $keepAlive = 15): string
    {
        $writer = new EventStreamWriter(time() + $maxSeconds, $keepAlive);

        ob_start();
        ob_start();

        try {
            $producer($writer);
        } finally {
            ob_end_flush();
            $output = ob_get_clean();
        }

        return $output;
    }

    public function testANamedEventCarriesItsNameOnTheWire(): void
    {
        $output = $this->capture(fn (EventStreamWriter $w) => $w->event('line', ['text' => 'hello']));

        $this->assertSame("event: line\ndata: {\"text\":\"hello\"}\n\n", $output);
    }

    public function testAnIdPrecedesItsOwnFrameRatherThanFormingOne(): void
    {
        $output = $this->capture(fn (EventStreamWriter $w) => $w->data(['n' => 1], 7));

        // The blank line appears once, at the end. An `id:` written as its own
        // frame would make the payload a second, unnumbered event -- which a
        // browser's EventSource would read as an id that resumes nothing.
        $this->assertSame("id: 7\ndata: {\"n\":1}\n\n", $output);
    }

    /**
     * The producer is package code, and its text must not be able to end the
     * frame early and forge the next one.
     */
    public function testNewlinesInAnEventNameCannotForgeAFrameBoundary(): void
    {
        $output = $this->capture(fn (EventStreamWriter $w) => $w->event("line\n\ndata: injected", []));

        // One frame, not two: the injected text is still there, but flattened
        // onto the event-name line where it is inert rather than sitting after
        // a boundary it manufactured.
        $this->assertSame(1, substr_count($output, "\n\n"));
        $this->assertStringStartsWith('event: line  data: injected', $output);
    }

    public function testACommentIsAFrameWithNoEvent(): void
    {
        $output = $this->capture(fn (EventStreamWriter $w) => $w->comment('keep-alive'));

        $this->assertSame(": keep-alive\n\n", $output);
    }

    public function testTheKeepAliveOnlyFiresAfterTheDeclaredSilence(): void
    {
        // Nothing has been silent yet, so nothing is due.
        $quiet = $this->capture(fn (EventStreamWriter $w) => $w->tick(), 60, 15);
        $this->assertSame('', $quiet);

        // A zero interval means every tick is due, which is how a producer that
        // wants a heartbeat per iteration gets one.
        $eager = $this->capture(fn (EventStreamWriter $w) => $w->tick(), 60, 0);
        $this->assertSame(": keep-alive\n\n", $eager);
    }

    public function testAProducerIsToldToStopOnceTheDeadlineHasPassed(): void
    {
        $expired = new EventStreamWriter(time() - 1, 15);
        $live = new EventStreamWriter(time() + 60, 15);

        $this->assertTrue($expired->shouldStop());
        $this->assertSame(0, $expired->remainingSeconds());
        $this->assertFalse($live->shouldStop());
    }

    public function testASlotIsGivenBackWhenTheProducerReturns(): void
    {
        $slots = $this->slots();

        $slot = $slots->acquire(['demo' => 1], 60);
        $this->assertNotNull($slot);
        $this->assertSame(1, $slots->held('demo'));

        $slot->release();
        $this->assertSame(0, $slots->held('demo'));
    }

    public function testReleasingTwiceDoesNotCreditABackASecondTime(): void
    {
        $slots = $this->slots();

        $slot = $slots->acquire(['demo' => 2], 60);
        $slots->acquire(['demo' => 2], 60);

        $slot->release();
        $slot->release();

        // Two taken, one released. A second credit would let the next caller in
        // over the ceiling -- and both release paths (the `finally` and the
        // shutdown function) can run for the same slot.
        $this->assertSame(1, $slots->held('demo'));
    }

    public function testTheCeilingRefusesTheConnectionPastIt(): void
    {
        $slots = $this->slots();

        $this->assertNotNull($slots->acquire(['demo' => 1], 60));
        $this->assertNull($slots->acquire(['demo' => 1], 60));
    }

    /**
     * A caller that passed one limit and failed another must leave no count
     * raised, or a deployment under load slowly locks out the users who keep
     * retrying -- each failed attempt having spent a place it never used.
     */
    public function testFailingOneLimitReleasesEveryOtherItAlreadyTook(): void
    {
        $slots = $this->slots();

        $slots->acquire(['narrow' => 1], 60);

        $this->assertNull($slots->acquire(['wide' => 10, 'narrow' => 1], 60));
        $this->assertSame(0, $slots->held('wide'));
        $this->assertSame(1, $slots->held('narrow'));
    }

    public function testOpeningPastTheCeilingRefusesWithRetryAfter(): void
    {
        $stream = new EventStream($this->slots());
        $limits = new EventStreamLimits(maxSeconds: 60, keepAliveSeconds: 15, slots: ['demo' => 1]);

        $stream->open($limits, fn () => null);

        try {
            $stream->open($limits, fn () => null);
            $this->fail('A second stream was admitted past the ceiling.');
        } catch (StreamUnavailableException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $exception->getHeaders());
        }
    }

    /**
     * The refusal has to happen while a worker is still free to send it. Taking
     * the slot inside the streamed body would mean the 503 was itself a
     * successfully opened stream that closed immediately, which a client cannot
     * tell from a server that had nothing to say.
     */
    public function testTheSlotIsTakenBeforeTheBodyIsSent(): void
    {
        $slots = $this->slots();
        $stream = new EventStream($slots);

        $stream->open(new EventStreamLimits(maxSeconds: 60, slots: ['demo' => 4]), fn () => null);

        $this->assertSame(1, $slots->held('demo'));
    }

    public function testTheResponseTellsAProxyNotToBufferIt(): void
    {
        $response = (new EventStream($this->slots()))
            ->open(new EventStreamLimits(maxSeconds: 60, keepAliveSeconds: 5), fn () => null);

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        // Symfony appends `private` of its own accord; what matters is that a
        // proxy is told not to keep this.
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame('60', $response->headers->get('X-Stream-Deadline-Seconds'));
        $this->assertSame('5', $response->headers->get('X-Stream-Keepalive-Seconds'));
    }

    public function testTheSlotIsGivenBackWhenAProducerThrows(): void
    {
        $slots = $this->slots();
        $response = (new EventStream($slots))->open(
            new EventStreamLimits(maxSeconds: 60, slots: ['demo' => 1]),
            function (): void {
                throw new \RuntimeException('the producer failed');
            }
        );

        // Nested for the same reason as capture(): the stream flushes its own
        // buffer, and a single level would put the opening comment on stdout.
        ob_start();
        ob_start();

        try {
            $response->sendContent();
            $this->fail('The producer exception did not surface.');
        } catch (\RuntimeException) {
            // Expected: the failure is the caller's to handle. What matters is
            // that it did not take a permanently held connection with it.
        } finally {
            ob_end_flush();
            ob_end_clean();
        }

        $this->assertSame(0, $slots->held('demo'));
    }
}
