<?php

namespace Everest\Tests\Unit\Jobs\Schedule;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Everest\Jobs\Schedule\RunScheduledSweepJob;
use Everest\Console\Commands\Auth\ProcessJGuardActivationsCommand;
use Everest\Console\Commands\Billing\RefreshNodeAvailabilityCommand;

/**
 * The minute-level sweeps used to cost a fresh `php artisan` process each.
 * They now run inside a booted worker, with the command itself unchanged.
 */
class RunScheduledSweepJobTest extends TestCase
{
    public function testTheJobRunsItsCommandInProcess(): void
    {
        Artisan::shouldReceive('call')->once()->with(RefreshNodeAvailabilityCommand::class);

        (new RunScheduledSweepJob(RefreshNodeAvailabilityCommand::class))->handle();
    }

    /** One pending run per sweep, never two of the same sweep at once. */
    public function testEachSweepIsUniqueOnItsOwn(): void
    {
        $this->assertSame(
            RefreshNodeAvailabilityCommand::class,
            (new RunScheduledSweepJob(RefreshNodeAvailabilityCommand::class))->uniqueId(),
        );
        $this->assertNotSame(
            (new RunScheduledSweepJob(RefreshNodeAvailabilityCommand::class))->uniqueId(),
            (new RunScheduledSweepJob(ProcessJGuardActivationsCommand::class))->uniqueId(),
        );
    }

    public function testTheJGuardSweepIsQueuedRatherThanSpawned(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => $event->description === 'ProcessJGuardActivationsCommand'));
        $this->assertFalse($events->contains(fn ($event) => str_contains((string) $event->command, 'jguard:process-activations')));
    }
}
