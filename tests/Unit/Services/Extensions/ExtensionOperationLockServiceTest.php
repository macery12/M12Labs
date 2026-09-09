<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;

class ExtensionOperationLockServiceTest extends TestCase
{
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
}
