<?php

namespace Everest\Services\Queue;

/**
 * Finds plain `queue:work` / `queue:listen` processes running this install's
 * artisan beside Horizon.
 *
 * An install upgraded from Jexactyl or Pterodactyl keeps its old worker unit
 * (`jxctl.service`, `pteroq.service`) enabled, and nothing in the import or
 * setup path retires it. On this panel's own box one sat on `standard` beside
 * Horizon for months: it takes jobs with `queue:work` defaults -- a 60 s
 * timeout, no memory cap, no Horizon metrics -- so a job Horizon would finish
 * in 90 s gets killed whenever the stray worker happens to grab it, and the
 * queue page under-reports the work it does. Horizon's own worker is
 * `horizon:work`, so any `queue:work` for this path is by definition not ours.
 *
 * Reads /proc directly rather than shelling out to `ps`, so it works from
 * PHP-FPM with exec functions disabled. Linux only; anywhere without /proc
 * it finds nothing rather than guessing.
 */
class StrayWorkerDetector
{
    private const WORKER_COMMANDS = ['queue:work', 'queue:listen'];

    private string $basePath;

    /**
     * $procRoot is injectable so the scan is testable against a fixture tree.
     */
    public function __construct(
        private string $procRoot = '/proc',
        ?string $basePath = null,
    ) {
        $this->basePath = $this->normalize($basePath ?? base_path()) ?? '';
    }

    /**
     * @return list<array{pid: int, command: string, queues: list<string>, unit: ?string}>
     */
    public function find(): array
    {
        if ($this->basePath === '' || !is_dir($this->procRoot)) {
            return [];
        }

        $found = [];

        foreach (glob($this->procRoot . '/[0-9]*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $args = $this->argsOf($dir);

            if ($args === null || !$this->isWorkerForThisInstall($dir, $args)) {
                continue;
            }

            $found[] = [
                'pid' => (int) basename($dir),
                'command' => implode(' ', $args),
                'queues' => $this->queuesFrom($args),
                'unit' => $this->unitOf($dir),
            ];
        }

        usort($found, fn (array $a, array $b) => $a['pid'] <=> $b['pid']);

        return $found;
    }

    /**
     * @return list<string>|null
     */
    private function argsOf(string $dir): ?array
    {
        // Processes exit between the glob and the read; that is not an error.
        $cmdline = @file_get_contents($dir . '/cmdline');

        if (!is_string($cmdline) || $cmdline === '') {
            return null;
        }

        return array_values(array_filter(explode("\0", $cmdline), fn (string $arg) => $arg !== ''));
    }

    /**
     * @param list<string> $args
     */
    private function isWorkerForThisInstall(string $dir, array $args): bool
    {
        foreach ($args as $index => $arg) {
            if (basename($arg) !== 'artisan') {
                continue;
            }

            $command = $args[$index + 1] ?? null;

            if (!in_array($command, self::WORKER_COMMANDS, true)) {
                return false;
            }

            return $this->artisanDirectory($dir, $arg) === $this->basePath;
        }

        return false;
    }

    /**
     * Where the artisan being run lives. A relative path is resolved against
     * the process's working directory, which is only readable for processes of
     * the same user; one that cannot be attributed to this install is left
     * alone rather than reported on a guess -- another panel on the same host
     * runs its own workers legitimately.
     */
    private function artisanDirectory(string $dir, string $artisan): ?string
    {
        if (str_starts_with($artisan, '/')) {
            return $this->normalize(dirname($artisan));
        }

        $cwd = @readlink($dir . '/cwd');

        if (!is_string($cwd)) {
            return null;
        }

        return $this->normalize($cwd . '/' . dirname($artisan));
    }

    /**
     * @param list<string> $args
     *
     * @return list<string>
     */
    private function queuesFrom(array $args): array
    {
        foreach ($args as $index => $arg) {
            $value = match (true) {
                str_starts_with($arg, '--queue=') => substr($arg, 8),
                $arg === '--queue' => $args[$index + 1] ?? '',
                default => null,
            };

            if ($value !== null) {
                return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $q) => $q !== ''));
            }
        }

        // `queue:work` with no --queue drains the connection's default queue.
        return [];
    }

    /**
     * The systemd unit that owns the process, so the fix can name it. On the
     * unified hierarchy this is the single `0::/system.slice/<unit>` line.
     */
    private function unitOf(string $dir): ?string
    {
        $cgroup = @file_get_contents($dir . '/cgroup');

        if (!is_string($cgroup)) {
            return null;
        }

        return preg_match('#/([^/\s]+\.service)\s*$#m', $cgroup, $matches) === 1 ? $matches[1] : null;
    }

    private function normalize(string $path): ?string
    {
        $real = @realpath($path);

        return is_string($real) ? rtrim($real, '/') : null;
    }
}
