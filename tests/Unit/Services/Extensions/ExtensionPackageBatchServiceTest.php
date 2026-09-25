<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionPackage;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionPackageBatchService;
use Everest\Services\Extensions\ExtensionPanelRebuildService;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\ExtensionPackageUpdateService;
use Everest\Services\Extensions\ExtensionPackageInstallService;
use Everest\Services\Extensions\ExtensionInstallProgressService;
use Everest\Services\Extensions\ExtensionPackageUninstallService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;

class ExtensionPackageBatchServiceTest extends TestCase
{
    public function testDuplicateItemsAreRejectedBeforeAnyLifecycleWork(): void
    {
        [$service, $install, $uninstall, $update, $rebuild, $lock] = $this->service();

        $install->shouldNotReceive('prepareInstall');
        $uninstall->shouldNotReceive('prepareUninstall');
        $update->shouldNotReceive('prepareUpdate');
        $rebuild->shouldNotReceive('rebuild');
        $lock->shouldNotReceive('withinLock');

        foreach (['batchInstall', 'batchUpdate', 'batchUninstall'] as $method) {
            try {
                $service->{$method}([
                    ['extensionId' => 'demo', 'repositoryId' => 1],
                    ['extensionId' => 'demo', 'repositoryId' => 1],
                ]);
                $this->fail(sprintf('%s accepted a duplicate extension id.', $method));
            } catch (DisplayException $exception) {
                $this->assertStringContainsString('appears more than once', $exception->getMessage());
            }
        }
    }

    public function testBatchUpdateRollsBackAllDatabaseWritesAndFilesInReverseOrder(): void
    {
        [$service, , , $update, $rebuild, $lock, $ownership, $progress] = $this->service();
        $first = ['extensionId' => 'one'];
        $second = ['extensionId' => 'two'];
        $firstHash = str_repeat('a', 64);
        $secondHash = str_repeat('b', 64);
        $settingKey = 'batch-atomic-' . bin2hex(random_bytes(4));

        $lock->expects('withinLock')
            ->once()
            ->with('update', 'batch', \Mockery::type('callable'))
            ->andReturnUsing(fn (string $action, string $target, callable $callback) => $callback());
        $progress->allows('report');
        $progress->expects('clear')->once();
        $ownership->allows('repairStandardPaths');

        $update->expects('prepareUpdate')
            ->once()
            ->with('one', 1, '1.1.0', $firstHash, true)
            ->andReturn($first);
        $update->expects('prepareUpdate')
            ->once()
            ->with('two', 2, '2.1.0', $secondHash, false)
            ->andReturn($second);

        $rebuild->expects('rebuild')->once()->with(\Mockery::type('string'), \Mockery::type('callable'));
        $rebuild->expects('rebuild')->once()->with('batch-update rollback');

        $update->expects('finalizeUpdate')->once()->with($first)->andReturnUsing(function () use ($settingKey): ExtensionPackage {
            DB::table('settings')->insert(['key' => $settingKey, 'value' => 'must-roll-back']);

            return new ExtensionPackage();
        });
        $update->expects('finalizeUpdate')->once()->with($second)->andThrow(new \RuntimeException('second finalizer failed'));
        $update->shouldNotReceive('completeUpdate');
        $update->expects('rollbackUpdate')->once()->with($second)->ordered();
        $update->expects('rollbackUpdate')->once()->with($first)->ordered();
        $update->expects('cleanupPreparedUpdate')->once()->with($first);
        $update->expects('cleanupPreparedUpdate')->once()->with($second);

        try {
            $service->batchUpdate([
                [
                    'extensionId' => 'one',
                    'repositoryId' => 1,
                    'version' => '1.1.0',
                    'approvedCapabilityHash' => $firstHash,
                    'acknowledgeModified' => true,
                ],
                [
                    'extensionId' => 'two',
                    'repositoryId' => 2,
                    'version' => '2.1.0',
                    'approvedCapabilityHash' => $secondHash,
                ],
            ]);
            $this->fail('The failed finalizer should abort the batch.');
        } catch (DisplayException $exception) {
            $this->assertSame('Failed to complete the batch update.', $exception->getMessage());
        }

        $this->assertFalse(DB::table('settings')->where('key', $settingKey)->exists());
    }

    public function testMultiExtensionUninstallRejectsIrrecoverableDataDropBeforeLifecycleWork(): void
    {
        [$service, , $uninstall, , $rebuild, $lock] = $this->service();

        $uninstall->shouldNotReceive('prepareUninstall');
        $rebuild->shouldNotReceive('rebuild');
        $lock->shouldNotReceive('withinLock');

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Uninstall extensions with data removal one at a time');

        $service->batchUninstall([
            ['extensionId' => 'one', 'dropData' => true],
            ['extensionId' => 'two', 'dropData' => false],
        ]);
    }

    public function testPostCommitProgressFailureDoesNotCompensateCommittedFiles(): void
    {
        [$service, , , $update, $rebuild, $lock, $ownership, $progress] = $this->service();
        $prepared = ['extensionId' => 'demo'];
        $package = new ExtensionPackage();

        $lock->expects('withinLock')
            ->once()
            ->with('update', 'batch', \Mockery::type('callable'))
            ->andReturnUsing(fn (string $action, string $target, callable $callback) => $callback());
        $progress->shouldReceive('report')->andReturnUsing(function (string $action, string $extensionId, string $stage): void {
            if ($stage === 'completed') {
                throw new \RuntimeException('progress file unavailable');
            }
        });
        $progress->expects('clear')->once();
        $ownership->allows('repairStandardPaths');
        $update->expects('prepareUpdate')->once()->with('demo', 1, null, null, false)->andReturn($prepared);
        $update->expects('finalizeUpdate')->once()->with($prepared)->andReturn($package);
        $update->expects('completeUpdate')->once()->with($prepared);
        $update->shouldNotReceive('rollbackUpdate');
        $update->expects('cleanupPreparedUpdate')->once()->with($prepared);
        $rebuild->expects('rebuild')->once()->with(\Mockery::type('string'), \Mockery::type('callable'));

        $result = $service->batchUpdate([
            ['extensionId' => 'demo', 'repositoryId' => 1],
        ]);

        $this->assertSame([$package], $result);
    }

    /**
     * @return array{ExtensionPackageBatchService, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface, \Mockery\MockInterface}
     */
    private function service(): array
    {
        $install = \Mockery::mock(ExtensionPackageInstallService::class);
        $uninstall = \Mockery::mock(ExtensionPackageUninstallService::class);
        $update = \Mockery::mock(ExtensionPackageUpdateService::class);
        $rebuild = \Mockery::mock(ExtensionPanelRebuildService::class);
        $lock = \Mockery::mock(ExtensionOperationLockService::class);
        $lock->allows('checkpoint');
        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $progress = \Mockery::mock(ExtensionInstallProgressService::class);

        return [
            new ExtensionPackageBatchService($install, $uninstall, $update, $rebuild, $lock, $ownership, $progress),
            $install,
            $uninstall,
            $update,
            $rebuild,
            $lock,
            $ownership,
            $progress,
        ];
    }
}
