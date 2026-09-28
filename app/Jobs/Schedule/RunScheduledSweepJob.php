<?php

namespace Everest\Jobs\Schedule;

use Everest\Jobs\Job;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Runs a minute-level database sweep inside an already-booted Horizon worker.
 *
 * `$schedule->command()` starts a fresh `php artisan` process per entry, and
 * the scheduler ran several of these every minute on an idle panel -- each one
 * paying a full framework boot (with CLI OPcache off, ~300 ms of CPU) to run
 * one indexed query that usually finds nothing. Queued, the boot is already
 * paid for.
 *
 * The command stays the single implementation and can still be run by hand;
 * this only changes where the scheduled run happens.
 *
 * Unique until processed, so a worker that falls behind never piles up more
 * than one pending run per sweep, and two runs of the same sweep never overlap.
 * One try: a sweep that fails is simply run again on the next tick.
 */
#[Timeout(120)]
#[Tries(1)]
class RunScheduledSweepJob extends Job implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;

    /** Longest a stuck run can hold the uniqueness lock. */
    public int $uniqueFor = 300;

    /**
     * @param class-string<\Illuminate\Console\Command> $command
     */
    public function __construct(public string $command)
    {
    }

    public function uniqueId(): string
    {
        return $this->command;
    }

    public function handle(): void
    {
        Artisan::call($this->command);
    }
}
