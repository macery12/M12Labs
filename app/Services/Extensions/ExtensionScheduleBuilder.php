<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Console\Scheduling\Schedule;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

/**
 * The restricted Schedule a package's schedule.php actually receives.
 *
 * Handing over the real Schedule hands over exec() and call(), which are
 * arbitrary code execution on the panel host at an interval of the package's
 * choosing, outside every capability the manifest declares. This exposes one
 * verb — command() — and only for commands the package declared under
 * capabilities.commands.
 *
 * Every registered entry is forced withoutOverlapping() and onOneServer(): a
 * schedule that piles up because one run is slow is the standard way a
 * background task takes a panel down, and it is not something a package author
 * should have to remember.
 *
 * Sub-minute frequencies are refused outright. The scheduler process would run
 * package code every few seconds with no way for an operator to see why load
 * climbed, and no legitimate extension task needs it.
 */
class ExtensionScheduleBuilder
{
    /** Frequency verbs a package may use. Everything below a minute is absent. */
    private const ALLOWED_FREQUENCIES = [
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes',
        'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'hourlyAt', 'everyTwoHours', 'everyThreeHours', 'everySixHours',
        'daily', 'dailyAt', 'twiceDaily', 'weekly', 'weeklyOn', 'monthly', 'monthlyOn',
        'quarterly', 'yearly',
        'timezone', 'between', 'unlessBetween', 'days', 'weekdays', 'weekends',
    ];

    public function __construct(
        private Schedule $schedule,
        private string $extensionId,
        private ExtensionCapabilitySet $capabilities,
    ) {
    }

    /**
     * Schedule one of the extension's own artisan commands.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function command(string $command, array $parameters = []): ExtensionScheduledTask
    {
        $name = trim(explode(' ', trim($command))[0]);
        $extensionId = $this->extensionId;

        if (!in_array($name, $this->capabilities->commands, true)) {
            throw new DisplayException(sprintf('The extension [%s] scheduled [%s], which it did not declare under capabilities.commands.', $this->extensionId, $name));
        }

        $event = $this->schedule->command($command, $parameters)
            ->withoutOverlapping()
            ->onOneServer()
            // schedule:work is a long-lived process. The event may have been
            // registered while the extension was runnable, then become
            // disabled/revoked or lose this declaration in an update. Filter
            // at execution time so stale scheduler state cannot run it.
            ->when(function () use ($extensionId, $name): bool {
                $entry = app(ExtensionRuntimePlanService::class)->entry($extensionId);

                return $entry !== null
                    && $entry->capabilities->schedule
                    && in_array($name, $entry->capabilities->commands, true);
            })
            ->runInBackground();

        return new ExtensionScheduledTask($event, $this->extensionId);
    }

    /**
     * @return array<int, string>
     */
    public static function allowedFrequencies(): array
    {
        return self::ALLOWED_FREQUENCIES;
    }
}
