<?php

namespace Everest\Services\Extensions;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Registers scheduled tasks contributed by installed extension packages.
 *
 * Each package may ship an app/Extensions/Packages/<id>/schedule.php that
 * returns a closure receiving an {@see ExtensionScheduleBuilder}. A file is
 * loaded only when the package declared capabilities.schedule and the
 * {@see ExtensionRuntimePlanService} permits it to run — so a package that ships
 * a schedule.php it never declared registers nothing, and a disabled extension
 * never registers its entries.
 * Because a disabled extension's commands are also unregistered, there is
 * nothing left for its schedule to reference either.
 */
class ExtensionScheduleService
{
    public function __construct(private ExtensionRuntimePlanService $plan)
    {
    }

    public function register(Schedule $schedule): void
    {
        foreach ($this->plan->withCapability('schedule') as $extensionId => $entry) {
            $file = app_path(sprintf('Extensions/Packages/%s/schedule.php', $extensionId));
            if (!is_file($file)) {
                continue;
            }

            // Parse errors surface as \Error, so \Throwable is required here:
            // one broken schedule.php must not take down cron for everything.
            try {
                $callback = require $file;
                if (is_callable($callback)) {
                    // The package never sees the real Schedule. See
                    // ExtensionScheduleBuilder: exec() and call() on it are
                    // arbitrary code execution on the panel host, on a timer
                    // the package chooses, outside every declared capability.
                    $callback(new ExtensionScheduleBuilder($schedule, $extensionId, $entry->capabilities));
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
