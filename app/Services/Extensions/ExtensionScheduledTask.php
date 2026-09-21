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
 *
 * The verbs are proxied through `__call()` against
 * {@see ExtensionScheduleBuilder::allowedFrequencies()}, so they are listed
 * here as well. Without that a package's `schedule.php` cannot be statically
 * analysed at all: every frequency reads as a call to a method that does not
 * exist, and the one mistake worth catching -- a verb outside the allowlist --
 * is buried in the noise of the ones that are fine.
 *
 * @method self everyMinute()
 * @method self everyTwoMinutes()
 * @method self everyThreeMinutes()
 * @method self everyFourMinutes()
 * @method self everyFiveMinutes()
 * @method self everyTenMinutes()
 * @method self everyFifteenMinutes()
 * @method self everyThirtyMinutes()
 * @method self hourly()
 * @method self hourlyAt(int|array<int, int>|string $offset)
 * @method self everyTwoHours()
 * @method self everyThreeHours()
 * @method self everySixHours()
 * @method self daily()
 * @method self dailyAt(string $time)
 * @method self twiceDaily(int $first = 1, int $second = 13)
 * @method self weekly()
 * @method self weeklyOn(array<int, int>|int|string $dayOfWeek, string $time = '0:0')
 * @method self monthly()
 * @method self monthlyOn(int $dayOfMonth = 1, string $time = '0:0')
 * @method self quarterly()
 * @method self yearly()
 * @method self timezone(\DateTimeZone|string $timezone)
 * @method self between(string $startTime, string $endTime)
 * @method self unlessBetween(string $startTime, string $endTime)
 * @method self days(array<int, int|string>|mixed $days)
 * @method self weekdays()
 * @method self weekends()
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
