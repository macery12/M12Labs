<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionPanelRebuildService;

/**
 * The rebuild is the most failure-prone step in the extension lifecycle, so the
 * guards around it are pinned here. The build itself is not executed: these
 * cover the conditions that must stop a build before it starts.
 */
class ExtensionPanelRebuildServiceTest extends IntegrationTestCase
{
    private const BUILD_LOCK = 'm12labs:extensions:build';

    public function tearDown(): void
    {
        Cache::lock(self::BUILD_LOCK)->forceRelease();
        Cache::forget('m12labs:extensions:build-context');
        Cache::forget('m12labs:extensions:build-generation');
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Builds must be serialized independently of the lifecycle operation lock,
     * so a manually triggered rebuild cannot run concurrently with an install
     * and leave the two racing over public/build.
     */
    public function testConcurrentRebuildIsRefused(): void
    {
        $held = Cache::lock(self::BUILD_LOCK, 60);
        $this->assertTrue($held->get(), 'expected to acquire the build lock for the test');

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Another panel rebuild is already running.');

        app(ExtensionPanelRebuildService::class)->rebuild('test');
    }

    /**
     * Failing to acquire the lock must not release the holder's lock. The
     * release lives in a finally attached to the acquired path only; moving it
     * would let a second caller cancel the first caller's build mid-flight.
     */
    public function testRefusedRebuildDoesNotReleaseTheHeldLock(): void
    {
        $held = Cache::lock(self::BUILD_LOCK, 60);
        $this->assertTrue($held->get());

        try {
            app(ExtensionPanelRebuildService::class)->rebuild('test');
            $this->fail('expected the rebuild to be refused');
        } catch (DisplayException) {
            // expected
        }

        $contender = Cache::lock(self::BUILD_LOCK, 10);
        $this->assertFalse($contender->get(), 'the original holder should still own the build lock');
    }

    public function testBuildLeaseOutlivesItsFormerFixedTtl(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');
        config()->set('extensions.build.timeout_seconds', 900);

        try {
            app(ExtensionPanelRebuildService::class)->rebuild('outer test', function (int $stage): void {
                if ($stage !== 0) {
                    return;
                }

                CarbonImmutable::setTestNow(now()->addSeconds(1021));

                try {
                    app(ExtensionPanelRebuildService::class)->rebuild('contender');
                    $this->fail('The former fixed build TTL admitted a contender.');
                } catch (DisplayException $exception) {
                    $this->assertStringContainsString('Another panel rebuild is already running.', $exception->getMessage());
                }

                throw new \RuntimeException('stop before commands');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('stop before commands', $exception->getMessage());
        }
    }

    public function testLostBuildFenceAbortsBeforeTheFirstCommand(): void
    {
        $replacement = null;

        try {
            app(ExtensionPanelRebuildService::class)->rebuild('lease-loss test', function (int $stage) use (&$replacement): void {
                $this->assertSame(0, $stage);
                Cache::lock(self::BUILD_LOCK)->forceRelease();
                $replacement = Cache::lock(self::BUILD_LOCK, 60);
                $this->assertTrue($replacement->get());
            });
            $this->fail('The stale rebuild owner should have been fenced out.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('lease was lost', $exception->getMessage());
        }

        $this->assertFalse(Cache::lock(self::BUILD_LOCK, 60)->get(), 'the stale rebuild must not release its successor');
        $replacement?->release();
    }
}
