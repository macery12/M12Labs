<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

/**
 * `capabilities.queues[].longRunning` — which of the two extension lanes a
 * package's jobs ride, and how long one of them may be declared to run.
 *
 * Those are the same question. `retry_after` belongs to a *connection*, and a
 * job allowed to outlive it has its reservation migrated while it is still
 * inside handle(), so a second worker starts the same job: two invoices, two
 * imports, two of whatever the job does. The short extension lane rides the
 * short connection, which is why a timeout above it is refused rather than
 * accepted and hoped about, and why the way to ask for more is to move lane.
 */
class ExtensionQueueLaneTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * PHPUnit runs on the `sync` connection, which has no retry_after at all
     * and therefore no lane ceiling to test against. These are the numbers the
     * panel actually ships.
     */
    private function onTheShippedTopology(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.long_connection' => 'redis-long',
            'queue.connections.redis.retry_after' => 300,
            'queue.connections.redis-long.retry_after' => 3900,
        ]);
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private function manifest(array $capabilities = []): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'A demo package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.1 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => [['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('a', 64)]],
        ];
    }

    /** @param array<string, mixed> $queue */
    private function queue(array $queue): QueueDefinition
    {
        $manifest = $this->parser->parse($this->manifest(['queues' => [$queue]]));

        return $manifest->capabilities->queues[0];
    }

    public function testAGroupIsShortLivedUnlessItSaysOtherwise(): void
    {
        $queue = $this->queue(['name' => 'sync']);

        $this->assertFalse($queue->longRunning);
        $this->assertSame(QueueDefinition::LANE, $queue->lane());
    }

    public function testADeclaredLongRunningGroupRidesTheLongLane(): void
    {
        $queue = $this->queue(['name' => 'import', 'longRunning' => true, 'timeoutSeconds' => 900]);

        $this->assertTrue($queue->longRunning);
        $this->assertSame(QueueDefinition::LONG_LANE, $queue->lane());
        $this->assertSame(900, $queue->timeoutSeconds);
    }

    /**
     * Cast would make `"false"` true. This flag decides whether a job gets a
     * dedicated worker for an hour, so a typo has to be an error.
     */
    public function testLongRunningMustBeAnActualBoolean(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('capabilities.queues[0].longRunning must be true or false.');

        $this->queue(['name' => 'import', 'longRunning' => 'true']);
    }

    // P5.1 — the defect this closes.

    public function testATimeoutAboveTheShortLanesRetryAfterIsRefused(): void
    {
        $this->onTheShippedTopology();

        try {
            $this->queue(['name' => 'import', 'timeoutSeconds' => 900]);
            $this->fail('A 900s job was accepted onto a lane that re-reserves after 300s.');
        } catch (DisplayException $exception) {
            $message = $exception->getMessage();

            // Both numbers, and the two ways out. An author who is only told
            // "invalid" cannot act on it.
            $this->assertStringContainsString('900s', $message);
            $this->assertStringContainsString('300s', $message);
            $this->assertStringContainsString('"longRunning": true', $message);
            $this->assertStringContainsString('299', $message);
        }
    }

    public function testTheSameTimeoutIsAcceptedOnTheLongLane(): void
    {
        $this->onTheShippedTopology();

        $queue = $this->queue(['name' => 'import', 'longRunning' => true, 'timeoutSeconds' => 900]);

        $this->assertSame(900, $queue->timeoutSeconds);
    }

    public function testTheLongLaneHasACeilingOfItsOwn(): void
    {
        $this->onTheShippedTopology();
        config(['queue.connections.redis-long.retry_after' => 600]);

        try {
            $this->queue(['name' => 'import', 'longRunning' => true, 'timeoutSeconds' => 900]);
            $this->fail('A 900s job was accepted onto a long lane that re-reserves after 600s.');
        } catch (DisplayException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('599', $message);

            // There is nowhere further to escalate to, so advising it would be
            // advice that cannot be taken.
            $this->assertStringNotContainsString('longRunning', $message);
        }
    }

    /**
     * An operator who tightens the connection after install is refused the
     * *next* update, which is the only moment there is anyone left to tell.
     */
    public function testLoweringRetryAfterNarrowsWhatMayBeDeclared(): void
    {
        $this->onTheShippedTopology();
        config(['queue.connections.redis.retry_after' => 120]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('119');

        $this->queue(['name' => 'sync', 'timeoutSeconds' => 200]);
    }

    /**
     * `sync` and `sqs` have no retry_after — sqs uses a visibility timeout set
     * on the queue itself — so there is no second ceiling to derive and the
     * absolute one stands alone.
     */
    public function testADriverWithoutRetryAfterImposesNoLaneCeiling(): void
    {
        config(['queue.default' => 'sync', 'queue.long_connection' => null]);

        $queue = $this->queue(['name' => 'import', 'timeoutSeconds' => 3600]);

        $this->assertSame(3600, $queue->timeoutSeconds);
    }

    public function testTheAbsoluteCeilingStillApplies(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must be between 1 and ' . ExtensionCapabilityVocabulary::QUEUE_MAX_TIMEOUT_SECONDS);

        $this->queue(['name' => 'import', 'longRunning' => true, 'timeoutSeconds' => 7200]);
    }

    // Compatibility.

    /**
     * The constraint that governs every new capability key here.
     *
     * `capability_hash` is taken over the projection at install, and
     * ExtensionRuntimePlanService re-hashes the *stored* copy on every plan
     * build to catch a database edit. An unconditional `longRunning` key would
     * change the hash of every package installed before the long lane existed
     * and take the lot inert on upgrade.
     */
    public function testAShortGroupsProjectionIsUnchangedByThisKeyExisting(): void
    {
        $projection = (new QueueDefinition(name: 'sync', maxAttempts: 5, timeoutSeconds: 120))->jsonSerialize();

        $this->assertArrayNotHasKey('longRunning', $projection);
        $this->assertSame(
            ['name' => 'sync', 'maxAttempts' => 5, 'timeoutSeconds' => 120, 'backoffSeconds' => [10, 60, 300]],
            $projection,
        );
    }

    public function testDeclaringItChangesTheHash(): void
    {
        $short = new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import')]);
        $long = new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', longRunning: true)]);

        $this->assertNotSame($short->hash(), $long->hash());
    }

    // What an administrator is shown.

    public function testMovingAGroupOntoTheLongLaneIsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import')]),
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', longRunning: true)]),
        );

        $this->assertTrue($diff->isEscalation());
        $this->assertStringContainsString('long-running', implode(' ', $diff->escalations));
    }

    /**
     * The timeout is how long one job holds a worker, so widening it is a
     * different ask even when the lane does not change. This was invisible
     * before: the diff flattened a queue to its name alone.
     */
    public function testWideningATimeoutIsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', timeoutSeconds: 60)]),
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', timeoutSeconds: 280)]),
        );

        $this->assertTrue($diff->isEscalation());
        $this->assertStringContainsString('280s', implode(' ', $diff->escalations));
    }

    public function testAnUnchangedGroupIsNotAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', timeoutSeconds: 60)]),
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', timeoutSeconds: 60)]),
        );

        $this->assertFalse($diff->isEscalation());
        $this->assertSame([], $diff->added);
    }

    // The third bound: on the way back out of storage.

    public function testATamperedStoredTimeoutIsClampedToItsLane(): void
    {
        $this->onTheShippedTopology();

        $capabilities = app(ExtensionRuntimePlanService::class)->hydrateCapabilities([
            'queues' => [['name' => 'sync', 'timeoutSeconds' => 3000]],
        ]);

        // Clamped rather than dropped: a tampered ceiling becomes the honest
        // one, not an absent limit.
        $this->assertSame(299, $capabilities->queues[0]->timeoutSeconds);
        $this->assertSame(QueueDefinition::LANE, $capabilities->queues[0]->lane());
    }

    public function testTheLongLaneIsClampedToItsOwnCeilingNotTheShortOne(): void
    {
        $this->onTheShippedTopology();

        $capabilities = app(ExtensionRuntimePlanService::class)->hydrateCapabilities([
            'queues' => [['name' => 'import', 'longRunning' => true, 'timeoutSeconds' => 3000]],
        ]);

        $this->assertSame(3000, $capabilities->queues[0]->timeoutSeconds);
        $this->assertSame(QueueDefinition::LONG_LANE, $capabilities->queues[0]->lane());
    }

    /**
     * An operator who lowers QUEUE_RETRY_AFTER after install tightens every
     * package at once, without reinstalling any of them.
     */
    public function testTighteningTheConnectionTightensInstalledPackages(): void
    {
        $this->onTheShippedTopology();
        config(['queue.connections.redis.retry_after' => 60]);

        $capabilities = app(ExtensionRuntimePlanService::class)->hydrateCapabilities([
            'queues' => [['name' => 'sync', 'timeoutSeconds' => 120]],
        ]);

        $this->assertSame(59, $capabilities->queues[0]->timeoutSeconds);
    }

    /** An unsigned local package must not be able to hold a worker at all. */
    public function testAnUnverifiedPackageMayNotHoldQueues(): void
    {
        $service = new ExtensionSignatureService(new ExtensionManifestCanonicalizer());

        $restricted = $service->restrictedCapabilitiesForUnverified(
            new ExtensionCapabilitySet(queues: [new QueueDefinition(name: 'import', longRunning: true)])
        );

        $this->assertContains('queues', $restricted);
    }
}
