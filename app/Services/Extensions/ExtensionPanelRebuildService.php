<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Everest\Exceptions\DisplayException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Rebuilds the panel after an extension changes files on disk.
 *
 * Extension pages are compiled into the panel bundle, so every install, update
 * and uninstall pays for a frontend build on the panel host. That build is the
 * single most failure-prone step in the lifecycle — it needs Node, RAM and
 * several minutes — so it is bounded here: pinned toolchain, capped wall clock
 * and heap, capped output, and a hardlink snapshot of the previous assets that
 * is restored if anything goes wrong.
 *
 * Vite is configured with `emptyOutDir: true`, so it wipes public/build before
 * it writes. A build that fails partway therefore leaves the panel with no
 * usable assets unless the previous set can be put back — which is exactly what
 * the snapshot is for. Hardlinks make it cheap and, because they keep the
 * inodes alive, immune to Vite deleting the originals.
 */
class ExtensionPanelRebuildService
{
    /**
     * Serializes builds independently of the lifecycle operation lock, so a
     * manually triggered rebuild cannot run concurrently with an install.
     */
    private const BUILD_LOCK = 'm12labs:extensions:build';

    public function __construct(
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
    }

    /**
     * Run the fixed rebuild hooks required after filesystem changes.
     *
     * An optional callback receives the zero-based command index just before
     * each command runs, allowing callers to report progress stages at the
     * correct moment (e.g. 'optimizing' before optimize:clear, 'building'
     * before the frontend build).
     *
     * @return array<int, array{command: string, output: string, durationMs: int}>
     */
    public function rebuild(string $reason, ?callable $onCommandStart = null): array
    {
        $lock = Cache::lock(self::BUILD_LOCK, (int) config('extensions.build.timeout_seconds', 900) + 120);

        if (!$lock->get()) {
            throw new DisplayException('Another panel rebuild is already running. Wait for it to finish before starting a new one.');
        }

        try {
            return $this->runRebuild($reason, $onCommandStart);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<int, array{command: string, output: string, durationMs: int}>
     */
    private function runRebuild(string $reason, ?callable $onCommandStart): array
    {
        $buildCommand = $this->getFrontendBuildCommand();
        $commands = [
            ['php', 'artisan', 'optimize:clear'],
            $buildCommand,
        ];

        $output = [];
        $environment = $this->getProcessEnvironment($reason);
        $snapshot = null;

        foreach ($commands as $index => $command) {
            if ($onCommandStart !== null) {
                $onCommandStart($index);
            }

            if ($index === 1) {
                // Validate (and auto-repair when root) filesystem ownership so
                // permission problems produce a clear error instead of a
                // cryptic mid-build failure.
                $this->ownershipService->validateBuildWorkspaceOwnership();
                $snapshot = $this->snapshotAssets();
            }

            $startedAt = microtime(true);
            $process = new Process($command, base_path(), $environment);
            $process->setTimeout($index === 1 ? (float) config('extensions.build.timeout_seconds', 900) : 300.0);
            $process->run();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $combinedOutput = trim($process->getOutput() . "\n" . $process->getErrorOutput());
            $output[] = [
                'command' => implode(' ', $command),
                'output' => $combinedOutput,
                'durationMs' => $durationMs,
            ];

            if (!$process->isSuccessful()) {
                $this->restoreAssets($snapshot);

                throw new DisplayException(sprintf('M12Labs rebuild failed while running "%s".', implode(' ', $command)), new \RuntimeException($combinedOutput));
            }
        }

        try {
            $this->assertOutputWithinBudget();
        } catch (DisplayException $exception) {
            $this->restoreAssets($snapshot);

            throw $exception;
        }

        $this->pruneSnapshots();
        $this->pruneStore($environment);

        return $output;
    }

    /**
     * Copy the current assets into a hardlink tree so a failed build can be
     * rolled back. Returns null when there is nothing to preserve (a first
     * build, or a host without a usable `cp -al`), which simply means a failure
     * has nothing to restore rather than that the build is blocked.
     */
    private function snapshotAssets(): ?string
    {
        $buildPath = public_path('build');
        if (!File::isDirectory($buildPath)) {
            return null;
        }

        $snapshotRoot = storage_path('app/extensions/asset-snapshots');
        File::ensureDirectoryExists($snapshotRoot);
        $snapshot = $snapshotRoot . '/' . now()->format('Ymd-His') . '-' . Str::random(8);

        // -a preserves modes/timestamps, -l hardlinks rather than copying, so a
        // 6 MB asset tree costs directory entries rather than 6 MB of disk.
        $process = new Process(['cp', '-al', $buildPath, $snapshot], base_path());
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !File::isDirectory($snapshot)) {
            File::deleteDirectory($snapshot);

            return null;
        }

        return $snapshot;
    }

    /**
     * Put the previous assets back after a failed build, keeping the broken
     * output for diagnosis. Both moves are renames within the same filesystem,
     * so the window in which public/build does not exist is as short as
     * possible.
     */
    private function restoreAssets(?string $snapshot): void
    {
        if ($snapshot === null || !File::isDirectory($snapshot)) {
            return;
        }

        $buildPath = public_path('build');
        $failedPath = $buildPath . '.failed.' . now()->format('Ymd-His');

        try {
            if (File::isDirectory($buildPath)) {
                @rename($buildPath, $failedPath);
            }

            if (!@rename($snapshot, $buildPath)) {
                // The snapshot is the only remaining copy; leave it in place
                // rather than losing it, and put the failed output back so the
                // panel is at least serving something.
                if (File::isDirectory($failedPath) && !File::isDirectory($buildPath)) {
                    @rename($failedPath, $buildPath);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A build that produced an implausibly large asset tree is treated as a
     * failure: it is far more likely to be a package inlining something huge
     * than a legitimate panel bundle.
     */
    private function assertOutputWithinBudget(): void
    {
        $budget = (int) config('extensions.build.max_output_bytes', 0);
        if ($budget <= 0) {
            return;
        }

        $buildPath = public_path('build');
        if (!File::isDirectory($buildPath)) {
            throw new DisplayException('The frontend build reported success but produced no assets.');
        }

        $bytes = 0;
        foreach (File::allFiles($buildPath) as $file) {
            $bytes += $file->getSize();

            if ($bytes > $budget) {
                throw new DisplayException(sprintf('The frontend build produced more than the permitted %s of assets. Review the extension\'s bundled files.', $this->formatBytes($budget)));
            }
        }
    }

    /**
     * Keep the most recent snapshot (the previous good asset set) for the
     * configured window so in-flight browsers can still fetch old chunks, and
     * drop everything older.
     */
    private function pruneSnapshots(): void
    {
        $snapshotRoot = storage_path('app/extensions/asset-snapshots');
        if (!File::isDirectory($snapshotRoot)) {
            return;
        }

        $cutoff = now()->subHours(max(1, (int) config('extensions.build.snapshot_retention_hours', 24)));

        $directories = collect(File::directories($snapshotRoot))
            ->sortByDesc(fn (string $path): int => (int) File::lastModified($path))
            ->values();

        // Index 0 is the set this rebuild just replaced — always retained.
        foreach ($directories->slice(1) as $directory) {
            if (File::lastModified($directory) < $cutoff->getTimestamp()) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function pruneStore(array $environment): void
    {
        if (!config('extensions.build.prune_store', true)) {
            return;
        }

        $pnpm = (new ExecutableFinder())->find('pnpm');
        if (!$pnpm) {
            return;
        }

        // Best effort: a store that could not be pruned is a disk-usage
        // concern, never a reason to fail an otherwise successful install.
        $process = new Process([$pnpm, 'store', 'prune'], base_path(), $environment);
        $process->setTimeout(300);
        $process->run();
    }

    /**
     * @return array<int, string>
     */
    private function getFrontendBuildCommand(): array
    {
        $pnpm = (new ExecutableFinder())->find('pnpm');

        if (!$pnpm) {
            throw new DisplayException('Unable to rebuild M12Labs because pnpm is not available on this host. Install the pnpm version pinned in package.json.');
        }

        // Deliberately no npm fallback: pnpm-lock.yaml is the only lockfile in
        // this repo, and npm would resolve a different dependency tree than the
        // one the panel is tested against.
        if (!File::exists(base_path('pnpm-lock.yaml'))) {
            throw new DisplayException('Unable to rebuild M12Labs because pnpm-lock.yaml is missing from the panel root.');
        }

        $this->assertToolchainVersions($pnpm);

        return [$pnpm, 'build'];
    }

    /**
     * Verify the host toolchain matches what the repo pins. A pnpm major other
     * than the pinned one resolves the lockfile differently, and a Node below
     * the engines floor fails deep inside the build with an unhelpful error.
     */
    private function assertToolchainVersions(string $pnpm): void
    {
        if (!config('extensions.build.enforce_toolchain', true)) {
            return;
        }

        $manifest = json_decode((string) File::get(base_path('package.json')), true);
        if (!is_array($manifest)) {
            return;
        }

        $pinnedPnpm = (string) ($manifest['packageManager'] ?? '');
        if (preg_match('/^pnpm@(\d+)\./', $pinnedPnpm, $matches)) {
            $actual = $this->probeVersion([$pnpm, '--version']);

            if ($actual !== null && !str_starts_with($actual, $matches[1] . '.')) {
                throw new DisplayException(sprintf('This panel pins %s but the host has pnpm %s. Install the pinned major version before installing extensions.', $pinnedPnpm, $actual));
            }
        }

        $requiredNode = (string) ($manifest['engines']['node'] ?? '');
        if (preg_match('/>=\s*(\d+)\.(\d+)\.(\d+)/', $requiredNode, $matches)) {
            $node = (new ExecutableFinder())->find('node');
            $actual = $node ? $this->probeVersion([$node, '--version']) : null;

            if ($actual !== null) {
                $required = sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);

                if (version_compare(ltrim($actual, 'v'), $required, '<')) {
                    throw new DisplayException(sprintf('This panel requires Node %s or newer but the host has %s. Upgrade Node before installing extensions.', $required, ltrim($actual, 'v')));
                }
            }
        }
    }

    /**
     * @param array<int, string> $command
     */
    private function probeVersion(array $command): ?string
    {
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput()) ?: null;
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024) . ' MB'
            : round($bytes / 1024) . ' KB';
    }

    /**
     * @return array<string, string>
     */
    private function getProcessEnvironment(string $reason): array
    {
        $home = storage_path('app/extensions/runtime-home');
        $cache = $home . '/.cache';
        $corepack = $cache . '/corepack';
        $npmCache = $cache . '/npm';
        $pnpmStore = $home . '/.local/share/pnpm/store';
        $pnpmHome = $home . '/.local/share/pnpm';
        $xdgData = $home . '/.local/share';
        $xdgState = $home . '/.local/state';

        File::ensureDirectoryExists($corepack);
        File::ensureDirectoryExists($npmCache);
        File::ensureDirectoryExists($pnpmHome);
        File::ensureDirectoryExists($pnpmStore);
        File::ensureDirectoryExists($xdgState);

        return [
            'M12LABS_EXTENSION_REBUILD_REASON' => $reason,
            // The rebuild runs head-less from PHP with no TTY. Keep pnpm in CI
            // mode so script execution never pauses for an interactive prompt.
            'CI' => 'true',
            'PNPM_CONFIG_CONFIRM_MODULES_PURGE' => 'false',
            'npm_config_confirm_modules_purge' => 'false',
            // Node sizes its heap from total system memory, which on a host
            // shared with game servers leads to the build being OOM-killed
            // rather than failing cleanly. Bound it explicitly.
            'NODE_OPTIONS' => sprintf('--max-old-space-size=%d', max(512, (int) config('extensions.build.node_max_old_space_mb', 3072))),
            // Applies only if this service ever runs an install: pnpm does not
            // honour it for `pnpm run`, so it does not suppress the build or its
            // postbuild hook. The real protection for this code path is that no
            // dependency install is ever performed here.
            'NPM_CONFIG_IGNORE_SCRIPTS' => 'true',
            'npm_config_ignore_scripts' => 'true',
            'HOME' => $home,
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'XDG_CACHE_HOME' => $cache,
            'XDG_DATA_HOME' => $xdgData,
            'XDG_STATE_HOME' => $xdgState,
            'COREPACK_HOME' => $corepack,
            'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0',
            'npm_config_cache' => $npmCache,
            'NPM_CONFIG_CACHE' => $npmCache,
            'PNPM_HOME' => $pnpmHome,
            'PNPM_STORE_DIR' => $pnpmStore,
            'pnpm_config_store_dir' => $pnpmStore,
            // Keep using the host pnpm binary instead of switching into
            // a runtime-managed CLI under storage (can fail with EACCES on
            // hardened/noexec mounts during extension install rebuilds).
            'NPM_CONFIG_MANAGE_PACKAGE_MANAGER_VERSIONS' => 'false',
            'npm_config_manage_package_manager_versions' => 'false',
            'PNPM_CONFIG_MANAGE_PACKAGE_MANAGER_VERSIONS' => 'false',
            'pnpm_config_manage_package_manager_versions' => 'false',
        ];
    }
}
