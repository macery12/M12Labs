<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Console\Scheduling\Event;

/**
 * A scheduled entry, narrowed to frequency verbs.
 *
 * Proxying rather than returning the Event is the point: the Event exposes
 * `cron()` (which accepts sub-minute expressions on some drivers), `then()`,
 * `onSuccess()` and `onFailure()`, all of which take a closure and would put
 * arbitrary package code back into the scheduler process.
 */
class ExtensionScheduledTask
{
    public function __construct(
        private Event $event,
        private string $extensionId,
    ) {
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): self
    {
        if (!in_array($method, ExtensionScheduleBuilder::allowedFrequencies(), true)) {
            throw new DisplayException(sprintf('The extension [%s] called [%s] on a scheduled task, which is not an allowed frequency.', $this->extensionId, $method));
        }

        $this->event->{$method}(...$arguments);

        return $this;
    }
}
