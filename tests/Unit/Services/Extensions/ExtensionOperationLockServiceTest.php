<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Carbon\CarbonImmutable;
use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionLockLease;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;

class ExtensionOperationLockServiceTest extends TestCase
{
    private const LOCK_KEY = 'm12labs:extensions:operation-lock';
    private const CONTEXT_KEY = 'm12labs:extensions:operation-context';
    private const GENERATION_KEY = 'm12labs:extensions:operation-generation';

    protected function tearDown(): void
    {
        Cache::lock(self::LOCK_KEY)->forceRelease();
        Cache::forget(self::CONTEXT_KEY);
        Cache::forget(self::GENERATION_KEY);
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function testProductionLifecycleOperationRefusesRootExecution(): void
    {
        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $ownership->shouldReceive('isRunningAsRoot')->once()->andReturnTrue();

        $service = new ExtensionOperationLockService($ownership);
        $original = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->expectException(DisplayException::class);
            $this->expectExceptionMessage('may not run as root');
            $service->withinLock('update', 'demo', fn () => null);
        } finally {
            app()->detectEnvironment(fn (): string => $original);
        }
    }

    public function testTestingEnvironmentCanExerciseLifecycleAsRoot(): void
    {
        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $ownership->shouldNotReceive('isRunningAsRoot');

        $result = (new ExtensionOperationLockService($ownership))->withinLock(
            'install',
            'demo',
            fn (): string => 'done',
        );

        $this->assertSame('done', $result);
    }

    public function testLeaseOutlivesTheFormerTtlAndRenewsWhileWorkIsActive(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 12:00:00');
        config()->set('extensions.lifecycle.lock_ttl_seconds', 7200);

        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $ownership->shouldNotReceive('isRunningAsRoot');
        $service = new ExtensionOperationLockService($ownership);

        $generation = $service->withinLock('update', 'demo', function () use ($service): int {
            $firstGeneration = $service->activeGeneration();
            $this->assertNotNull($firstGeneration);

            CarbonImmutable::setTestNow(now()->addSeconds(1801));
            $this->assertFalse(Cache::lock(self::LOCK_KEY, 60)->get(), 'the former fixed TTL must not admit a contender');

            $service->checkpoint();
            CarbonImmutable::setTestNow(now()->addSeconds(7100));
            $this->assertFalse(Cache::lock(self::LOCK_KEY, 60)->get(), 'the active checkpoint must renew the lease');

            return $firstGeneration;
        });

        $this->assertGreaterThan(0, $generation);
        $this->assertTrue(Cache::lock(self::LOCK_KEY, 60)->get(), 'completion should release the lease');
    }

    public function testFenceLossStopsAllLaterMutationAndCannotReleaseTheSuccessor(): void
    {
        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $ownership->shouldNotReceive('isRunningAsRoot');
        $service = new ExtensionOperationLockService($ownership);
        $replacement = null;
        $mutated = false;

        try {
            $service->withinLock('uninstall', 'demo', function () use ($service, &$replacement, &$mutated): void {
                Cache::lock(self::LOCK_KEY)->forceRelease();
                $replacement = ExtensionLockLease::acquire(
                    self::LOCK_KEY,
                    self::CONTEXT_KEY,
                    self::GENERATION_KEY,
                    'replacement lifecycle operation',
                    7200,
                    ['action' => 'update', 'subject' => 'other'],
                );
                $this->assertNotNull($replacement);

                $service->checkpoint();
                $mutated = true;
            });
            $this->fail('The stale lifecycle owner should have been fenced out.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('lease was lost', $exception->getMessage());
        }

        $this->assertFalse($mutated);
        $this->assertFalse(Cache::lock(self::LOCK_KEY, 60)->get(), 'the stale owner must not release its successor');
        $replacement?->release();
    }
}
