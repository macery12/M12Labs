<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionQueueJob;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Extensions\Packages\fixture_queue\Jobs\SlowFixtureJob;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

class ExtensionQueueAtomicReservationTest extends IntegrationTestCase
{
    private string $barrierRoot;

    public function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The atomic dispatch regression requires pcntl.');
        }

        config()->set('modules.extensions.enabled', true);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.after_commit', false);

        $capabilities = new ExtensionCapabilitySet(
            queues: [new QueueDefinition(name: 'slow', maxOutstanding: 1)],
        );

        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes(
            'fixture_queue',
            $capabilities,
            ['app/Extensions/Packages/fixture_queue/Jobs/SlowFixtureJob.php' => "<?php\n"],
        ), [
            'name' => 'Queue fixture',
            'state' => 'enabled',
        ]));
        ExtensionConfig::create(['extension_id' => 'fixture_queue', 'enabled' => true]);
        ExtensionRuntimePlanService::flush();

        $this->barrierRoot = sys_get_temp_dir() . '/extension-queue-admission-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->barrierRoot);
    }

    protected function tearDown(): void
    {
        app(ExtensionQueueRegistry::class)->endDrain('fixture_queue');
        DB::table('jobs')->delete();
        ExtensionQueueJob::query()->where('extension_id', 'fixture_queue')->delete();
        DB::table('extension_queue_admissions')->where('extension_id', 'fixture_queue')->delete();
        ExtensionConfig::query()->where('extension_id', 'fixture_queue')->delete();
        ExtensionPackage::query()->where('extension_id', 'fixture_queue')->delete();
        ExtensionRuntimePlanService::flush();
        File::deleteDirectory($this->barrierRoot);

        parent::tearDown();
    }

    public function testConcurrentDispatchersAtomicallyAdmitOneReservationAndPayload(): void
    {
        $pids = [];

        for ($index = 0; $index < 2; ++$index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a concurrent queue dispatcher.');
            }

            if ($pid === 0) {
                $this->runDispatcher($index);
            }

            $pids[] = $pid;
        }

        $this->waitForFiles(['ready-0', 'ready-1']);
        File::put($this->barrierRoot . '/go', 'go');

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        }

        $results = [
            trim((string) File::get($this->barrierRoot . '/result-0')),
            trim((string) File::get($this->barrierRoot . '/result-1')),
        ];

        $this->assertCount(1, array_filter($results, static fn (string $result): bool => $result === 'accepted'), implode(', ', $results));
        $this->assertCount(1, array_filter($results, static fn (string $result): bool => $result === 'rejected'), implode(', ', $results));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, ExtensionQueueJob::query()->where('extension_id', 'fixture_queue')->count());
        $this->assertSame(1, app(ExtensionQueueRegistry::class)->outstanding('fixture_queue', 'slow'));
    }

    private function runDispatcher(int $index): never
    {
        try {
            DB::statement('PRAGMA busy_timeout = 5000');
            ExtensionRuntimePlanService::flush();

            File::put($this->barrierRoot . '/ready-' . $index, 'ready');
            $this->waitForFiles(['go']);

            try {
                SlowFixtureJob::dispatch();
                $result = 'accepted';
            } catch (DisplayException) {
                $result = 'rejected';
            } catch (\Throwable $exception) {
                $result = 'error:' . $exception::class . ':' . $exception->getMessage();
            }

            File::put($this->barrierRoot . '/result-' . $index, $result);
            $this->terminateChild();
        } catch (\Throwable $exception) {
            File::put($this->barrierRoot . '/result-' . $index, 'error:' . $exception::class . ':' . $exception->getMessage());
            $this->terminateChild();
        }
    }

    /** Avoid inheriting PHPUnit and database-cleanup shutdown handlers. */
    private function terminateChild(): never
    {
        posix_kill(posix_getpid(), SIGKILL);

        // Satisfy the never return type on platforms where the signal failed.
        exit(1);
    }

    /**
     * @param array<int, string> $names
     */
    private function waitForFiles(array $names): void
    {
        $deadline = microtime(true) + 10;

        do {
            $errors = [];
            foreach ([0, 1] as $index) {
                $resultPath = $this->barrierRoot . '/result-' . $index;
                if (File::exists($resultPath)) {
                    $result = trim((string) File::get($resultPath));
                    if (str_starts_with($result, 'error:')) {
                        $errors[] = $result;
                    }
                }
            }
            if ($errors !== []) {
                throw new \RuntimeException('Dispatcher failed before the barrier: ' . implode(', ', $errors));
            }

            $missing = array_filter($names, fn (string $name): bool => !File::exists($this->barrierRoot . '/' . $name));
            if ($missing === []) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Timed out waiting for dispatcher barrier files: ' . implode(', ', $missing));
    }
}
