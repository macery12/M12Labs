<?php

namespace Everest\Tests\Unit\Services\Queue;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Services\Queue\StrayWorkerDetector;

/**
 * An install upgraded from Jexactyl kept `jxctl.service` running a plain
 * `queue:work` on `standard` beside Horizon, killing our jobs at its 60 s
 * default. These pin what counts as that worker -- and, as much, what must
 * not: Horizon's own processes, and another panel's workers on the same host.
 */
class StrayWorkerDetectorTest extends TestCase
{
    private string $root;
    private string $proc;
    private string $panel;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/stray-worker-' . bin2hex(random_bytes(6));
        $this->proc = $this->root . '/proc';
        $this->panel = $this->root . '/panel';

        File::makeDirectory($this->proc, 0755, true);
        File::makeDirectory($this->panel, 0755, true);
        File::makeDirectory($this->root . '/other-panel', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param list<string> $args
     */
    private function process(int $pid, array $args, ?string $cwd = null, ?string $cgroup = null): void
    {
        $dir = "{$this->proc}/{$pid}";
        File::makeDirectory($dir);
        file_put_contents("{$dir}/cmdline", implode("\0", $args) . "\0");

        if ($cwd !== null) {
            symlink($cwd, "{$dir}/cwd");
        }

        if ($cgroup !== null) {
            file_put_contents("{$dir}/cgroup", $cgroup . "\n");
        }
    }

    private function detector(): StrayWorkerDetector
    {
        return new StrayWorkerDetector($this->proc, $this->panel);
    }

    public function testALeftoverUnitRunningThisInstallsArtisanIsFoundAndNamed(): void
    {
        $this->process(515175, ['/usr/bin/php', "{$this->panel}/artisan", 'queue:work', '--queue=high,standard,low', '--sleep=3'], null, '0::/system.slice/jxctl.service');

        $this->assertSame([[
            'pid' => 515175,
            'command' => "/usr/bin/php {$this->panel}/artisan queue:work --queue=high,standard,low --sleep=3",
            'queues' => ['high', 'standard', 'low'],
            'unit' => 'jxctl.service',
        ]], $this->detector()->find());
    }

    public function testARelativeArtisanIsAttributedThroughTheWorkingDirectory(): void
    {
        $this->process(40, ['php', 'artisan', 'queue:listen', '--queue', 'standard'], $this->panel);

        $found = $this->detector()->find();

        $this->assertCount(1, $found);
        $this->assertSame(['standard'], $found[0]['queues']);
        $this->assertNull($found[0]['unit']);
    }

    public function testHorizonsOwnProcessesAreNeverReported(): void
    {
        $this->process(10, ['/usr/bin/php', "{$this->panel}/artisan", 'horizon'], $this->panel);
        $this->process(11, ['/usr/bin/php8.3', 'artisan', 'horizon:supervisor', 'x:supervisor-interactive', '--queue=standard'], $this->panel);
        $this->process(12, ['/usr/bin/php8.3', 'artisan', 'horizon:work', 'redis', '--queue=standard'], $this->panel);

        $this->assertSame([], $this->detector()->find());
    }

    /**
     * Another panel on the same host runs its own workers legitimately, and a
     * worker whose working directory cannot be read (another user's process)
     * cannot be attributed -- neither is ours to report.
     */
    public function testWorkersThatCannotBeAttributedToThisInstallAreLeftAlone(): void
    {
        $this->process(20, ['/usr/bin/php', "{$this->root}/other-panel/artisan", 'queue:work']);
        $this->process(21, ['php', 'artisan', 'queue:work'], "{$this->root}/other-panel");
        $this->process(22, ['php', 'artisan', 'queue:work']);

        $this->assertSame([], $this->detector()->find());
    }

    public function testNoQueueOptionMeansTheDefaultQueueAndResultsAreOrderedByPid(): void
    {
        $this->process(90, ['php', "{$this->panel}/artisan", 'queue:work']);
        $this->process(9, ['php', "{$this->panel}/artisan", 'queue:work', 'redis']);

        $found = $this->detector()->find();

        $this->assertSame([9, 90], array_column($found, 'pid'));
        $this->assertSame([[], []], array_column($found, 'queues'));
    }

    public function testAHostWithoutProcFindsNothing(): void
    {
        $this->assertSame([], (new StrayWorkerDetector($this->root . '/missing', $this->panel))->find());
    }
}
