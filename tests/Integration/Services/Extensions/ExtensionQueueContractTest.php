<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Illuminate\Queue\WorkerOptions;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionQueueJob;
use Illuminate\Support\Facades\Schema;
use Everest\Exceptions\DisplayException;
use Everest\Services\Queue\QueueTopology;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
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
        // and never raises the enqueue lifecycle events, which is exactly
        // where the quota, drain refusal and durable reservation live.
        // after_commit is disabled because DatabaseTransactions never commits,
        // so a deferred push would not happen at all.
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

        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes(
            'fixture_queue',
            $capabilities,
            ['app/Extensions/Packages/fixture_queue/Jobs/SlowFixtureJob.php' => "<?php\n"],
        ), [
            'name' => 'Queue fixture',
            'state' => $enabled ? 'enabled' : 'installed_disabled',
        ]));

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

    /**
     * The second lane, and the reason it exists. A group the manifest declared
     * long-running rides its own queue *and* its own connection — the queue so
     * an hour-long import does not sit in front of the same package's
     * thirty-second webhook, the connection because `retry_after` belongs to a
     * connection and a job that outlives it is handed to a second worker while
     * the first is still running it.
     */
    public function testADeclaredLongRunningGroupLandsOnTheLongLane(): void
    {
        $this->installFixture(new QueueDefinition(name: 'slow', timeoutSeconds: 120, longRunning: true));

        $job = new SlowFixtureJob();
        $topology = app(QueueTopology::class);

        $this->assertSame($topology->queueFor(QueueDefinition::LONG_LANE), $job->queue);
        $this->assertSame($topology->connectionFor(QueueDefinition::LONG_LANE), $job->connection);
        $this->assertNotNull($job->connection, 'The long lane inherited the short connection, which defeats the point of it.');
    }

    /**
     * A group nobody declared is about to be discarded by the enabled gate. The
     * short lane is the right place to throw something away; the long one would
     * hold a dedicated worker to do it.
     */
    public function testAnUndeclaredGroupFallsToTheShortLane(): void
    {
        $this->installFixture(new QueueDefinition(name: 'something-else', longRunning: true));

        $job = new SlowFixtureJob();

        $this->assertSame(app(QueueTopology::class)->queueFor(QueueDefinition::LANE), $job->queue);
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

    public function testAWorkerObservesDisablementWithoutFlushingItsRuntimePlan(): void
    {
        $this->installFixture();
        $registry = app(ExtensionQueueRegistry::class);

        $this->assertNotNull($registry->definition('fixture_queue', 'slow'));

        ExtensionConfig::query()
            ->where('extension_id', 'fixture_queue')
            ->update(['enabled' => false]);

        $this->assertNull($registry->definition('fixture_queue', 'slow'));
    }

    public function testAWorkerRefreshesTheRateLimiterFromTheLiveQueueContract(): void
    {
        $initial = new QueueDefinition(name: 'slow', rateLimit: '2/minute');
        $this->installFixture($initial);

        (new SlowFixtureJob())->middleware();

        $limiter = app(CacheRateLimiter::class)->limiter($initial->limiterName('fixture_queue'));
        $this->assertNotNull($limiter);
        $limit = $limiter(new SlowFixtureJob());
        $this->assertSame(2, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);

        $updated = new ExtensionCapabilitySet(
            queues: [new QueueDefinition(name: 'slow', rateLimit: '5/hour')],
        );
        File::deleteDirectory(base_path('app/Extensions/Packages/fixture_queue'));
        $signedUpdate = $this->signedRuntimePackageAttributes(
            'fixture_queue',
            $updated,
            ['app/Extensions/Packages/fixture_queue/Jobs/SlowFixtureJob.php' => "<?php\n"],
            '1.1.0',
        );
        ExtensionPackage::query()
            ->where('extension_id', 'fixture_queue')
            ->update([
                'installed_version' => $signedUpdate['installed_version'],
                'manifest' => $signedUpdate['manifest'],
                'signed_manifest' => $signedUpdate['signed_manifest'],
                'manifest_hash' => $signedUpdate['manifest_hash'],
                'capabilities' => $signedUpdate['capabilities'],
                'capability_hash' => $signedUpdate['capability_hash'],
                'package_checksum' => $signedUpdate['package_checksum'],
                'signature_key_id' => $signedUpdate['signature_key_id'],
                'signature_verified_at' => $signedUpdate['signature_verified_at'],
            ]);

        (new SlowFixtureJob())->middleware();

        $limiter = app(CacheRateLimiter::class)->limiter($initial->limiterName('fixture_queue'));
        $limit = $limiter(new SlowFixtureJob());
        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(3600, $limit->decaySeconds);
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

    /** A journal outage must abort before the queue driver receives a payload. */
    public function testDispatchFailsClosedWhenTheJournalCannotReserveThePayload(): void
    {
        $this->installFixture();
        Schema::rename('extension_queue_jobs', 'extension_queue_jobs_unavailable');

        try {
            try {
                SlowFixtureJob::dispatch();
                $this->fail('Dispatch should fail when its durable journal reservation cannot be written.');
            } catch (\Illuminate\Database\QueryException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(0, DB::table('jobs')->count());
        } finally {
            Schema::rename('extension_queue_jobs_unavailable', 'extension_queue_jobs');
        }
    }

    /** A backend push exception must release its pre-enqueue reservation. */
    public function testBackendPushFailureReconcilesTheOutstandingReservation(): void
    {
        $this->installFixture(new QueueDefinition(name: 'slow', maxOutstanding: 1));
        Schema::rename('jobs', 'jobs_unavailable');

        try {
            try {
                SlowFixtureJob::dispatch();
                $this->fail('Dispatch should surface the backend push failure.');
            } catch (\Illuminate\Database\QueryException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(0, ExtensionQueueJob::query()->where('extension_id', 'fixture_queue')->count());
            $this->assertSame(0, app(ExtensionQueueRegistry::class)->outstanding('fixture_queue', 'slow'));
        } finally {
            Schema::rename('jobs_unavailable', 'jobs');
        }
    }

    public function testTerminalTransitionReleasesTheAtomicOutstandingSlot(): void
    {
        $this->installFixture(new QueueDefinition(name: 'slow', maxOutstanding: 1));
        SlowFixtureJob::dispatch();

        $queue = app('queue')->connection('database');
        $backendJob = $queue->pop(app(QueueTopology::class)->queueFor('extensions'));
        $this->assertNotNull($backendJob);
        app('queue.worker')->process('database', $backendJob, new WorkerOptions());

        $this->assertSame(ExtensionQueueJob::STATUS_COMPLETED, ExtensionQueueJob::query()->value('status'));
        $this->assertSame(0, app(ExtensionQueueRegistry::class)->outstanding('fixture_queue', 'slow'));

        SlowFixtureJob::dispatch();

        $this->assertSame(1, app(ExtensionQueueRegistry::class)->outstanding('fixture_queue', 'slow'));
        $this->assertSame(1, DB::table('jobs')->count());
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
        $this->expectExceptionMessage('still has 1 queued or running backend job(s)');

        app(ExtensionJobDrainService::class)->assertSafeToRemove('fixture_queue');
    }

    /**
     * A stopped worker leaves the real database payload in place, so lifecycle
     * mutation is refused. Once a worker consumes it under the drain gate, the
     * middleware deletes the payload and acknowledges the journal row.
     */
    public function testDrainWaitsForTheBackendPayloadToBeDeleted(): void
    {
        $this->installFixture();
        SlowFixtureJob::dispatch();

        $drain = app(ExtensionJobDrainService::class);
        $drain->beginDrain('fixture_queue');

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, $drain->inFlight('fixture_queue'));
        $this->assertFalse($drain->waitForDrain('fixture_queue', 0, 0));

        try {
            $drain->assertSafeToRemove('fixture_queue');
            $this->fail('Lifecycle mutation should be refused while the backend payload exists.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('queued or running backend job', $exception->getMessage());
        }

        $queue = app('queue')->connection('database');
        $backendJob = $queue->pop(app(QueueTopology::class)->queueFor('extensions'));
        $this->assertNotNull($backendJob);
        app('queue.worker')->process('database', $backendJob, new WorkerOptions());

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $drain->inFlight('fixture_queue'));
        $this->assertTrue($drain->waitForDrain('fixture_queue', 0, 0));
        $this->assertSame(
            ExtensionQueueJob::STATUS_CANCELLED,
            ExtensionQueueJob::query()->where('extension_id', 'fixture_queue')->value('status'),
        );

        $drain->assertSafeToRemove('fixture_queue');
        $this->addToAssertionCount(1);
    }
}
