<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionQueueJob;
use Everest\Exceptions\DisplayException;
use Everest\Services\Queue\QueueTopology;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionJobDrainService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Extensions\Packages\fixture_queue\Jobs\SlowFixtureJob;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

/**
 * The queue contract an extension's jobs run under.
 *
 * The point of all of this is that a package supplies work, and the panel
 * supplies every property that decides how the queue treats it. These tests pin
 * the parts that would otherwise be trivially bypassed: which lane the job
 * lands on, where its retry and timeout come from, whose quota it counts
 * against, and what happens to it when the extension it belongs to is on its
 * way out.
 */
class ExtensionQueueContractTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimePlanService::flush();
        config()->set('modules.extensions.enabled', true);

        // A real driver, not Queue::fake(): the fake replaces the queue manager
        // and never raises JobQueueing/JobQueued, which is exactly where the
        // quota, the drain refusal and the journal live. after_commit is
        // disabled because DatabaseTransactions never commits, so a deferred
        // push would not happen at all.
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.after_commit', false);
    }

    public function tearDown(): void
    {
        ExtensionRuntimePlanService::flush();
        app(ExtensionQueueRegistry::class)->endDrain('fixture_queue');

        parent::tearDown();
    }

    private function installFixture(?QueueDefinition $queue = null, bool $enabled = true): void
    {
        $capabilities = new ExtensionCapabilitySet(
            queues: [$queue ?? new QueueDefinition(
                name: 'slow',
                maxAttempts: 5,
                timeoutSeconds: 120,
                backoffSeconds: [1, 2, 3],
            )],
        );

        ExtensionPackage::create([
            'extension_id' => 'fixture_queue',
            'package_id' => 'fixture_queue',
            'name' => 'Queue fixture',
            'icon' => 'puzzle',
            'installed_version' => '1.0.0',
            'manifest' => ['manifestVersion' => 3, 'extension' => ['id' => 'fixture_queue']],
            'manifest_version' => 3,
            // What ExtensionSignatureService::verify() records for a package
            // installed while no signing root was pinned. The column default is
            // 'unsigned', which no install path produces and which the runtime plan
            // refuses once a root exists — so a fixture that leaves it unset is not
            // a package this panel could actually have.
            'signature_state' => 'unsigned_acknowledged',
            'capabilities' => $capabilities->jsonSerialize(),
            'capability_hash' => $capabilities->hash(),
            'state' => $enabled ? 'enabled' : 'installed_disabled',
        ]);

        ExtensionConfig::create(['extension_id' => 'fixture_queue', 'enabled' => $enabled]);
        ExtensionRuntimePlanService::flush();
    }

    /**
     * Extension work rides one static lane, declared after every core lane so
     * supervisor-interactive (which runs balance => false) drains it last.
     */
    public function testExtensionJobsLandOnTheExtensionsLane(): void
    {
        $this->installFixture();

        $job = new SlowFixtureJob();

        $this->assertSame(app(QueueTopology::class)->queueFor('extensions'), $job->queue);
    }

    /** The owning extension comes from the namespace, not from a property. */
    public function testTheExtensionIdIsDerivedFromTheClassNamespace(): void
    {
        $this->assertSame('fixture_queue', (new SlowFixtureJob())->extensionId());
    }

    /**
     * Retry count and timeout are the manifest's, not the job's. A job able to
     * set its own could pin a worker for as long as it liked.
     */
    public function testRetryAndTimeoutComeFromTheManifest(): void
    {
        $this->installFixture();

        $job = new SlowFixtureJob();

        $this->assertSame(5, $job->tries());
        $this->assertSame(120, $job->timeout());
        $this->assertSame([1, 2, 3], $job->backoff());
    }

    /**
     * With no declaration to read — a disabled extension, or one that dropped
     * the group — the job falls back to a single attempt rather than to the
     * generous defaults it asked for.
     */
    public function testAnUndeclaredQueueGroupGetsNoBudget(): void
    {
        $job = new SlowFixtureJob();

        $this->assertSame(1, $job->tries());
        $this->assertSame(60, $job->timeout());
    }

    public function testDispatchIsRefusedForAnUndeclaredQueueGroup(): void
    {
        $this->installFixture(new QueueDefinition(name: 'other'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('did not declare the queue group [slow]');

        SlowFixtureJob::dispatch();
    }

    public function testDispatchIsRefusedWhileDraining(): void
    {
        $this->installFixture();
        app(ExtensionQueueRegistry::class)->beginDrain('fixture_queue');

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('being drained');

        SlowFixtureJob::dispatch();
    }

    /**
     * maxOutstanding is a quota on work already in flight, counted from the
     * journal rather than from a counter — a counter drifts whenever a worker
     * is killed, and drifts towards refusing legitimate work forever.
     */
    public function testDispatchIsRefusedOverTheOutstandingQuota(): void
    {
        $this->installFixture(new QueueDefinition(name: 'slow', maxOutstanding: 2));

        SlowFixtureJob::dispatch();
        SlowFixtureJob::dispatch();

        $this->assertSame(2, app(ExtensionQueueRegistry::class)->outstanding('fixture_queue', 'slow'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('maximum of 2 jobs in flight');

        SlowFixtureJob::dispatch();
    }

    /** Every dispatch is journalled, which is what makes a drain decidable. */
    public function testDispatchIsRecordedAgainstTheExtension(): void
    {
        $this->installFixture();

        SlowFixtureJob::dispatch();

        $row = ExtensionQueueJob::query()->where('extension_id', 'fixture_queue')->firstOrFail();

        $this->assertSame('slow', $row->queue_name);
        $this->assertSame(SlowFixtureJob::class, $row->job_class);
        $this->assertSame(ExtensionQueueJob::STATUS_QUEUED, $row->status);
        $this->assertNotNull($row->job_uuid);
    }

    /**
     * The uninstall guard. Deleting a package's class files while a worker
     * holds one of its jobs leaves a payload that can never be deserialized.
     */
    public function testRemovalIsRefusedWhileAJobIsRunning(): void
    {
        $this->installFixture();

        ExtensionQueueJob::create([
            'job_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'extension_id' => 'fixture_queue',
            'queue_name' => 'slow',
            'job_class' => SlowFixtureJob::class,
            'status' => ExtensionQueueJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('still has 1 job(s) running');

        app(ExtensionJobDrainService::class)->assertSafeToRemove('fixture_queue');
    }

    /** Queued work is discarded outright; running work is left to finish. */
    public function testDrainCancelsQueuedWorkAndLeavesRunningWorkAlone(): void
    {
        $this->installFixture();

        foreach ([ExtensionQueueJob::STATUS_QUEUED, ExtensionQueueJob::STATUS_RUNNING] as $status) {
            ExtensionQueueJob::create([
                'job_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'extension_id' => 'fixture_queue',
                'queue_name' => 'slow',
                'job_class' => SlowFixtureJob::class,
                'status' => $status,
            ]);
        }

        $drain = app(ExtensionJobDrainService::class);

        $this->assertSame(1, $drain->cancelQueued('fixture_queue'));
        $this->assertSame(1, $drain->inFlight('fixture_queue'));
        $this->assertSame(1, $drain->running('fixture_queue'));
    }
}
