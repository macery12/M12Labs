<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\CallQueuedClosure;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimeSnapshot;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

/**
 * A bare 401 used to build the runtime plan five times -- route registration
 * twice, queue sizing twice, bindings once -- each pass re-hashing every
 * package file and re-verifying the signature. The plan is now held for one
 * operation. These pin both halves of that: one build per operation, and
 * never a plan carried from one operation into the next or past a write that
 * changes it.
 */
class ExtensionRuntimeSnapshotTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public static ?bool $seenInJob = null;

    private int $builds = 0;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.extensions.enabled', true);
        ExtensionRuntimePlanService::flush();

        // The plan's only read of the package table joined to its configs.
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains($query->sql, 'extension_packages')
                && str_contains($query->sql, 'join')) {
                ++$this->builds;
            }
        });

        ExtensionPackage::query()->create(array_merge(
            $this->signedRuntimePackageAttributes(
                'snapshot_fixture',
                new ExtensionCapabilitySet(clientRoutes: true),
                ['app/Extensions/Packages/snapshot_fixture/routes/client.php' => "<?php\n"],
            ),
            ['state' => 'enabled'],
        ));
        ExtensionConfig::query()->create(['extension_id' => 'snapshot_fixture', 'enabled' => true]);
    }

    public function tearDown(): void
    {
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function plan(): ExtensionRuntimePlanService
    {
        return app(ExtensionRuntimePlanService::class);
    }

    public function testEveryCallerInOneOperationSharesOneBuild(): void
    {
        $this->builds = 0;

        // The request path's callers: route loading, queue sizing, bindings,
        // the access middleware and the hook dispatcher.
        $this->plan()->withCapability('routes.client');
        $this->plan()->withCapability('routes.admin');
        $this->plan()->withCapability('queues');
        $this->plan()->withCapability('bindings');
        $this->plan()->isEnabled('snapshot_fixture');
        $this->plan()->hooksFor('server.created');

        $this->assertSame(1, $this->builds);
        $this->assertArrayHasKey('snapshot_fixture', $this->plan()->plan());
    }

    public function testEachQueueJobReadsTheLiveState(): void
    {
        $this->assertTrue($this->plan()->isEnabled('snapshot_fixture'));

        // Disabled by another process: a write this one never observes.
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        DB::table('extension_configs')->where('extension_id', 'snapshot_fixture')->update(['enabled' => false]);
        $connection->setEventDispatcher($events);

        // Still this operation, so still its plan.
        $this->assertTrue($this->plan()->isEnabled('snapshot_fixture'));
        $this->builds = 0;

        // A real job through the sync driver, so the worker's own events fire.
        Queue::connection('sync')->push(CallQueuedClosure::create(function (): void {
            ExtensionRuntimeSnapshotTest::$seenInJob = app(ExtensionRuntimePlanService::class)->isEnabled('snapshot_fixture');
        }));

        $this->assertFalse(self::$seenInJob);
        $this->assertSame(1, $this->builds);
    }

    public function testTheContainerDropsTheSnapshotBetweenOperations(): void
    {
        $this->plan()->plan();
        $this->assertTrue(app(ExtensionRuntimeSnapshot::class)->held());

        $this->app->forgetScopedInstances();

        $this->assertFalse(app(ExtensionRuntimeSnapshot::class)->held());
    }

    /**
     * Lifecycle code writes through models, query builders and raw updates. An
     * operation that disables a package must see the disable on its next read,
     * however it was written.
     */
    public function testAWriteToThePlansTablesInvalidatesItWithinTheOperation(): void
    {
        $this->assertTrue($this->plan()->isEnabled('snapshot_fixture'));

        ExtensionConfig::query()->where('extension_id', 'snapshot_fixture')->update(['enabled' => false]);

        $this->assertFalse($this->plan()->isEnabled('snapshot_fixture'));
    }

    public function testUnrelatedWritesKeepTheSnapshot(): void
    {
        $this->plan()->plan();
        $this->builds = 0;

        DB::table('settings')->insert(['key' => 'snapshot:test', 'value' => '1']);
        $this->plan()->plan();

        $this->assertSame(0, $this->builds);
    }

    /** A plan read mid-transaction may describe rows the rollback removed. */
    public function testARolledBackTransactionInvalidatesIt(): void
    {
        try {
            DB::transaction(function (): void {
                $this->plan()->plan();

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse(app(ExtensionRuntimeSnapshot::class)->held());
    }

    /** A fresh install reads the plan before its tables exist; that must not stick. */
    public function testAFailedBuildIsNeverHeld(): void
    {
        $snapshot = new ExtensionRuntimeSnapshot();

        $this->assertSame(['plan' => [], 'core' => []], $snapshot->remember(fn () => null));
        $this->assertFalse($snapshot->held());
    }
}
