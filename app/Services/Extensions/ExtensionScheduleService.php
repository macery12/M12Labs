<?php

namespace Everest\Services\Extensions;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Registers scheduled tasks contributed by installed extension packages.
 *
 * Each package may ship an app/Extensions/Packages/<id>/schedule.php that
 * returns a closure receiving the Schedule. A file is loaded only when the
 * package declared capabilities.schedule and the {@see ExtensionRuntimePlanService}
 * permits it to run — so a package that ships a schedule.php it never declared
 * registers nothing, and a disabled extension never registers its entries.
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
        foreach (array_keys($this->plan->withCapability('schedule')) as $extensionId) {
            $file = app_path(sprintf('Extensions/Packages/%s/schedule.php', $extensionId));
            if (!is_file($file)) {
                continue;
            }

            // Parse errors surface as \Error, so \Throwable is required here:
            // one broken schedule.php must not take down cron for everything.
            try {
                $callback = require $file;
                if (is_callable($callback)) {
                    $callback($schedule);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
