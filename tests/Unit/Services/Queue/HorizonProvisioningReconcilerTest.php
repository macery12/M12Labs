<?php

namespace Everest\Tests\Unit\Services\Queue;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Services\Queue\HorizonProvisioningReconciler;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Horizon takes its supervisor list once, at start. An extension install's
 * frontend build restarts it before the package commits, so the long lane
 * came up unstaffed and a durable AI turn sat "running" with nobody to run it.
 */
class HorizonProvisioningReconcilerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'horizon.defaults' => [
                'supervisor-interactive' => ['maxProcesses' => 3, 'balance' => 'auto'],
                'supervisor-extensions-long' => ['processes' => 0, 'balance' => 'simple'],
            ],
            'horizon.environments' => [
                '*' => ['supervisor-interactive' => [], 'supervisor-extensions-long' => []],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    /** @param array<string, int> $running supervisor => maxProcesses */
    private function reconciler(array $running, int $longLane, bool $horizonUp = true): HorizonProvisioningReconciler
    {
        $masters = \Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn($horizonUp ? [(object) ['name' => 'host-abc']] : []);

        $supervisors = \Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn(array_map(
            fn (string $name, int $max) => (object) ['name' => 'host-abc:' . $name, 'options' => ['maxProcesses' => $max]],
            array_keys($running),
            $running,
        ));

        $queues = \Mockery::mock(ExtensionQueueRegistry::class);
        $queues->shouldReceive('longLaneProcesses')->andReturn($longLane);

        return new HorizonProvisioningReconciler($masters, $supervisors, $queues);
    }

    public function testAnUnstaffedLaneTheLivePlanNeedsIsMissing(): void
    {
        $this->assertSame(
            ['supervisor-extensions-long'],
            $this->reconciler(['supervisor-interactive' => 3], longLane: 4)->missing(),
        );
    }

    public function testNothingIsMissingWhileNoPackageNeedsTheLane(): void
    {
        $this->assertSame([], $this->reconciler(['supervisor-interactive' => 3], longLane: 0)->missing());
    }

    public function testALaneRunningAtTheWrongSizeCounts(): void
    {
        $this->assertSame(
            ['supervisor-extensions-long'],
            $this->reconciler(['supervisor-interactive' => 3, 'supervisor-extensions-long' => 1], longLane: 4)->missing(),
        );
    }

    /** An auto-balanced pool moves within its bounds on its own. */
    public function testAnAutoBalancedPoolIsNotJudgedByItsCount(): void
    {
        $this->assertSame([], $this->reconciler(['supervisor-interactive' => 1], longLane: 0)->missing());
    }

    /** Horizon being down is the process manager's to fix, not a reason to signal it. */
    public function testNothingHappensWhenHorizonIsNotRunning(): void
    {
        $this->assertSame([], $this->reconciler([], longLane: 4, horizonUp: false)->missing());
    }

    /** The scheduler waits for a second observation, so a restart in flight is left alone. */
    public function testTheScheduledCheckWaitsForASecondObservation(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->assertNull($this->reconciler(['supervisor-interactive' => 3], longLane: 4)->reconcile());
    }

    /** A lifecycle change is not held back by a restart something else just did. */
    public function testAnImmediateCheckIgnoresTheCooldownButStartsIt(): void
    {
        Artisan::shouldReceive('call')->twice()->with('horizon:terminate');

        $reconciler = $this->reconciler(['supervisor-interactive' => 3], longLane: 4);

        $this->assertSame('supervisor-extensions-long', $reconciler->reconcileNow());
        $this->assertSame('supervisor-extensions-long', $reconciler->reconcileNow());
        $this->assertTrue(Cache::has('queue:horizon:reprovision-cooldown'));
    }

    /** The scheduled check backs off inside the cooldown, so a bad plan cannot loop. */
    public function testTheScheduledCheckRespectsTheCooldown(): void
    {
        Artisan::shouldReceive('call')->once()->with('horizon:terminate');

        $reconciler = $this->reconciler(['supervisor-interactive' => 3], longLane: 4);
        $reconciler->reconcileNow();

        Cache::put('queue:horizon:missing-supervisors-since', time() - 120, 600);

        $this->assertNull($reconciler->reconcile());
    }
}
