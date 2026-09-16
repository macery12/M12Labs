<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Services\Streaming\EventStream;
use Everest\Services\Streaming\EventStreamLimits;
use Everest\Services\Streaming\EventStreamWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Exceptions\Service\Extension\StreamNotDeclaredException;

/**
 * Return a long-lived stream of events instead of one response.
 *
 * For work whose answer arrives in pieces — a build's output, a long import's
 * progress, an agent thinking out loud. The alternative a package would
 * otherwise reach for is the client polling an endpoint every second, which is
 * worse for both sides: latency you cannot fix and a request rate you cannot
 * distinguish from a loop that lost its exit.
 *
 * Declared in the manifest, as named kinds with their limits:
 *
 * ```json
 * "capabilities": {
 *   "streams": [{ "name": "build-log", "maxSeconds": 600, "maxConcurrentPerUser": 1 }]
 * }
 * ```
 *
 * and opened by that name:
 *
 * ```php
 * return PackageStreams::for('my_extension')->open('build-log', function (EventStreamWriter $out) use ($build) {
 *     foreach ($build->lines() as $line) {
 *         if ($out->shouldStop()) {
 *             return;
 *         }
 *
 *         $out->event('line', ['text' => $line]);
 *     }
 *
 *     $out->close();
 * });
 * ```
 *
 * **`shouldStop()` is not optional.** It is true when the deadline has passed
 * *or* the browser has gone away, and a producer that never asks keeps a PHP
 * worker busy for the full declared duration writing frames nobody reads. Call
 * it once per iteration of whatever loop produces frames.
 *
 * **Why this is a declared capability.** Everything else a package contributes
 * costs a worker for the length of a request. A stream costs one for as long as
 * it is open, which makes it the one surface that can exhaust the pool through
 * ordinary use rather than through a bug or an attack. The declaration is what
 * puts that in front of an administrator before they install, and what an
 * operator tightens afterwards from `config/extensions.php` without touching
 * any package.
 *
 * **Durability is not included.** If the connection drops, the stream is gone;
 * nothing replays what the client missed. A package that needs a reconnecting
 * client to catch up has to keep its own log and accept a cursor, which is what
 * the panel's own agent does. Say so in your UI rather than assuming delivery.
 */
final class PackageStreams
{
    public const CAPABILITY = 'streams';

    private function __construct(
        private string $extensionId,
        private ExtensionRuntimePlanService $plan,
        private EventStream $streams,
    ) {
    }

    public static function for(string $extensionId): self
    {
        return new self(
            $extensionId,
            app(ExtensionRuntimePlanService::class),
            app(EventStream::class),
        );
    }

    /**
     * Open a stream of the named kind.
     *
     * `$user` scopes the per-user accounting. Pass the acting user — without
     * one, every connection of this kind shares a single bucket, which means
     * one person can spend the whole allowance.
     *
     * @param callable(EventStreamWriter): void $producer
     *
     * @throws StreamNotDeclaredException when the kind was never declared, or the
     *                                    package is no longer enabled
     * @throws \Everest\Exceptions\Service\Streaming\StreamUnavailableException
     *                                                                          when no worker may be held right now
     */
    public function open(string $name, callable $producer, ?string $user = null): StreamedResponse
    {
        $declared = $this->plan->streamFor($this->extensionId, $name);

        if ($declared === null) {
            throw new StreamNotDeclaredException($this->extensionId, $name);
        }

        $ceiling = (int) config('extensions.streams.max_seconds', 900);
        $globalPerUser = (int) config('extensions.streams.max_concurrent_per_user', 4);
        $scope = $user ?? 'anonymous';

        // The manifest's number is a request; the deployment's is the answer.
        // Taking the lower of the two is what lets an operator tighten a busy
        // panel without reinstalling the packages running on it.
        $limits = new EventStreamLimits(
            maxSeconds: max(1, min($declared->maxSeconds, $ceiling)),
            keepAliveSeconds: $declared->keepAliveSeconds,
            slots: [
                // Every extension stream on the deployment.
                'ext:global' => max(1, (int) config('extensions.streams.max_concurrent', 32)),
                // Every extension stream this person holds, so someone with
                // five streaming packages installed cannot hold five times
                // whatever each declared for itself.
                'ext:user:' . $scope => max(1, $globalPerUser),
                // This kind, for this person.
                $declared->slotName($this->extensionId) . ':' . $scope => $declared->maxConcurrentPerUser,
            ],
            headers: ['X-Extension-Stream' => $this->extensionId . ':' . $name],
        );

        return $this->streams->open($limits, $producer);
    }
}
