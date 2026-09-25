<?php

namespace Everest\Tests\Unit\Extensions\Sdk;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Everest\Services\Streaming\EventStream;
use Everest\Services\Streaming\StreamSlots;
use Everest\Services\Streaming\EventStreamWriter;
use Everest\Extensions\Sdk\Services\PackageStreams;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Exceptions\Service\Extension\StreamNotDeclaredException;
use Everest\Exceptions\Service\Streaming\StreamUnavailableException;
use Everest\Services\Extensions\Manifest\Definitions\StreamDefinition;

/**
 * The gate in front of a package holding a connection open.
 *
 * Unlike the privileged services, a stream grants a package no reach it did not
 * already have — the same data could be served by a client polling. What it
 * costs is a PHP-FPM child for as long as it is open, which is the one thing a
 * package can spend that an operator cannot absorb without noticing. So the
 * tests here are about arithmetic and about who wins when the manifest and the
 * deployment disagree.
 */
class PackageStreamsTest extends TestCase
{
    /** @param array<int, StreamDefinition> $streams */
    private function planDeclaring(array $streams): void
    {
        $plan = \Mockery::mock(ExtensionRuntimePlanService::class);
        $plan->shouldReceive('streamFor')->andReturnUsing(
            function (string $id, string $name) use ($streams): ?StreamDefinition {
                if ($id !== 'demo') {
                    return null;
                }

                foreach ($streams as $stream) {
                    if ($stream->name === $name) {
                        return $stream;
                    }
                }

                return null;
            }
        );

        $this->app->instance(ExtensionRuntimePlanService::class, $plan);
        $this->app->instance(EventStream::class, new EventStream(new StreamSlots(Cache::store('array'))));
    }

    public function testAPackageCannotOpenAStreamItNeverDeclared(): void
    {
        $this->planDeclaring([new StreamDefinition('build-log')]);

        $this->expectException(StreamNotDeclaredException::class);

        PackageStreams::for('demo')->open('something-else', fn () => null);
    }

    /**
     * The limits are read from the live plan on every open, so a package
     * disabled while one connection is still being served cannot start another.
     */
    public function testADisabledPackageCannotOpenANewStream(): void
    {
        $this->planDeclaring([new StreamDefinition('build-log')]);

        $this->expectException(StreamNotDeclaredException::class);

        PackageStreams::for('not_installed')->open('build-log', fn () => null);
    }

    public function testADeclaredStreamOpens(): void
    {
        $this->planDeclaring([new StreamDefinition('build-log', 120, 10, 2)]);

        $response = PackageStreams::for('demo')->open('build-log', fn (EventStreamWriter $w) => $w->close(), 'user-1');

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('demo:build-log', $response->headers->get('X-Extension-Stream'));
        $this->assertSame('120', $response->headers->get('X-Stream-Deadline-Seconds'));
    }

    /**
     * The manifest's number is a request; the deployment's is the answer. This
     * is what lets an operator tighten a panel under load without reinstalling
     * the packages running on it.
     */
    public function testTheDeploymentCeilingWinsOverAWiderManifest(): void
    {
        config(['extensions.streams.max_seconds' => 30]);
        $this->planDeclaring([new StreamDefinition('build-log', 3600)]);

        $response = PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');

        $this->assertSame('30', $response->headers->get('X-Stream-Deadline-Seconds'));
    }

    /** A manifest asking for less than the ceiling keeps its own smaller number. */
    public function testATighterManifestIsNotWidenedToTheCeiling(): void
    {
        config(['extensions.streams.max_seconds' => 900]);
        $this->planDeclaring([new StreamDefinition('build-log', 45)]);

        $response = PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');

        $this->assertSame('45', $response->headers->get('X-Stream-Deadline-Seconds'));
    }

    public function testOnePersonCannotHoldMoreOfOneKindThanWasDeclared(): void
    {
        $this->planDeclaring([new StreamDefinition('build-log', 60, 15, 1)]);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');

        $this->expectException(StreamUnavailableException::class);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');
    }

    /**
     * The per-user ceiling has to be per user, or the first person to open a
     * stream would lock everyone else out of it.
     */
    public function testOnePersonHittingTheirCeilingDoesNotBlockAnother(): void
    {
        $this->planDeclaring([new StreamDefinition('build-log', 60, 15, 1)]);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');
        $second = PackageStreams::for('demo')->open('build-log', fn () => null, 'user-2');

        $this->assertSame(200, $second->getStatusCode());
    }

    /**
     * Someone with several streaming packages installed must not be able to
     * hold the sum of what each declared for itself.
     */
    public function testTheDeploymentsPerUserCeilingBoundsEveryPackageTogether(): void
    {
        config(['extensions.streams.max_concurrent_per_user' => 1]);
        $this->planDeclaring([
            new StreamDefinition('build-log', 60, 15, 5),
            new StreamDefinition('progress', 60, 15, 5),
        ]);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');

        $this->expectException(StreamUnavailableException::class);

        PackageStreams::for('demo')->open('progress', fn () => null, 'user-1');
    }

    public function testTheGlobalCeilingBoundsEveryUserTogether(): void
    {
        config(['extensions.streams.max_concurrent' => 1]);
        $this->planDeclaring([new StreamDefinition('build-log', 60, 15, 5)]);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-1');

        $this->expectException(StreamUnavailableException::class);

        PackageStreams::for('demo')->open('build-log', fn () => null, 'user-2');
    }
}
