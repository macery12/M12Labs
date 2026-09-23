<?php

namespace Everest\Services\Queue;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Restart Horizon when the supervisors it runs are not the ones it should.
 *
 * Horizon reads its provisioning plan once, when the command starts. Some of
 * that plan is decided at boot from live state -- the extensions long lane is
 * staffed only while an enabled package declares a long-running queue group --
 * so a Horizon that happened to start at the wrong moment keeps the wrong
 * shape until something restarts it. The wrong moment is not rare: an
 * extension install or update rebuilds the frontend *before* it commits the
 * package, the build's post-hook terminates Horizon, and the replacement boots
 * while the plan still says the package is not there. The lane then has jobs
 * and no worker, and a durable AI turn sits "running" until its deadline.
 *
 * Two entry points:
 *
 * - {@see reconcile()}, from the scheduler every minute. A supervisor must be
 *   missing on two consecutive checks before anything happens, so a Horizon
 *   that is mid-restart is left to finish.
 * - {@see reconcileNow()}, after an extension lifecycle change commits, when
 *   the mismatch is expected and waiting a minute would only delay the fix.
 *
 * The scheduled check honours a cooldown, so a plan Horizon cannot satisfy (a
 * supervisor that dies on boot) produces one restart every few minutes, not
 * one a minute; the immediate check starts it but does not wait on it.
 * `horizon:terminate` is graceful: running jobs finish first, and the process
 * manager starts the replacement.
 */
class HorizonProvisioningReconciler
{
    private const MISSING_SINCE_KEY = 'queue:horizon:missing-supervisors-since';

    private const COOLDOWN_KEY = 'queue:horizon:reprovision-cooldown';

    private const COOLDOWN_SECONDS = 300;

    public function __construct(
        private MasterSupervisorRepository $masters,
        private SupervisorRepository $supervisors,
        private ExtensionQueueRegistry $extensionQueues,
    ) {
    }

    /**
     * @return array<int, string> supervisors that should be running and are not,
     *                            or are running at a fixed size other than the
     *                            plan's; empty when Horizon is not running at
     *                            all, which is the process manager's problem
     */
    public function missing(): array
    {
        if ($this->masters->all() === []) {
            return [];
        }

        $running = [];
        foreach ($this->supervisors->all() as $supervisor) {
            $name = (string) $supervisor->name;
            $short = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
            $running[$short] = (array) ($supervisor->options ?? []);
        }

        $wrong = [];
        foreach ($this->desired() as $name => $options) {
            if (!isset($running[$name])) {
                $wrong[] = $name;

                continue;
            }

            // Only a fixed-size supervisor can be judged by its count; an
            // auto-balanced one moves between its bounds on its own.
            $fixed = (int) ($options['processes'] ?? 0);
            if ($fixed > 0 && ($options['balance'] ?? null) !== 'auto'
                && (int) ($running[$name]['maxProcesses'] ?? $fixed) !== $fixed) {
                $wrong[] = $name;
            }
        }

        return $wrong;
    }

    /** Scheduled: act only on a mismatch that has lasted a whole check. */
    public function reconcile(): ?string
    {
        $missing = $this->missing();

        if ($missing === []) {
            Cache::forget(self::MISSING_SINCE_KEY);

            return null;
        }

        // Numeric, not int: Redis hands back what was stored as a string, and
        // an is_int() here made every run look like the first sighting -- the
        // scheduled check reset its own clock each minute and never acted.
        $since = Cache::get(self::MISSING_SINCE_KEY);

        if (!is_numeric($since)) {
            Cache::put(self::MISSING_SINCE_KEY, time(), 600);

            return null;
        }

        if (time() - (int) $since < 50) {
            return null;
        }

        return $this->restart($missing);
    }

    /**
     * After a lifecycle change: the mismatch is expected, so do not wait, and
     * do not defer to a cooldown some earlier restart started -- an operator
     * installing two packages in five minutes would otherwise get the lane
     * for the first and not the second.
     */
    public function reconcileNow(): ?string
    {
        $missing = $this->missing();

        return $missing === [] ? null : $this->restart($missing, force: true);
    }

    /** @param array<int, string> $missing */
    private function restart(array $missing, bool $force = false): ?string
    {
        if ($force) {
            Cache::put(self::COOLDOWN_KEY, time(), self::COOLDOWN_SECONDS);
        } elseif (!Cache::add(self::COOLDOWN_KEY, time(), self::COOLDOWN_SECONDS)) {
            return null;
        }

        Cache::forget(self::MISSING_SINCE_KEY);

        Log::warning('Horizon is not running supervisors its provisioning plan requires; restarting it.', [
            'missing' => $missing,
        ]);

        if (!$this->terminate()) {
            return null;
        }

        return implode(', ', $missing);
    }

    /**
     * What `horizon:terminate` does, without needing the command.
     *
     * Horizon registers its commands only in a console process, so from the
     * drawer -- a web request, which is where an operator enables a package --
     * `Artisan::call('horizon:terminate')` failed with "command does not
     * exist" and the lane stayed unstaffed. Signalling the master directly
     * works from either: FPM runs as the same user Horizon does.
     */
    private function terminate(): bool
    {
        if (!function_exists('posix_kill')) {
            Log::error('Cannot restart Horizon: the posix extension is not available to this PHP process.');

            return false;
        }

        $sent = false;
        foreach ($this->masters->all() as $master) {
            // Only this host's masters; another host's Horizon is its own.
            if (!Str::startsWith((string) $master->name, MasterSupervisor::basename())) {
                continue;
            }

            if ($this->signal((int) $master->pid)) {
                $sent = true;
            } else {
                Log::error('Could not signal the Horizon master to restart.', [
                    'pid' => $master->pid,
                    'error' => function_exists('posix_strerror') ? posix_strerror(posix_get_last_error()) : null,
                ]);
            }
        }

        // What horizon:terminate also does, for any plain queue:work workers.
        Cache::forever('illuminate:queue:restart', now()->getTimestamp());

        return $sent;
    }

    /** Graceful: the master stops its supervisors, which finish their jobs. */
    protected function signal(int $pid): bool
    {
        return @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
    }

    /**
     * The supervisors this environment's plan staffs, as this process sees it.
     *
     * Read from the same plan Horizon would build if it started now -- this
     * process booted with the current runtime plan, which is the point.
     *
     * @return array<string, array<string, mixed>>
     */
    private function desired(): array
    {
        // The same merge and the same first-match lookup as
        // ProvisioningPlan::deploy(), whose parsed plan is not public.
        $defaults = (array) config('horizon.defaults', []);
        $supervisors = [];

        foreach ((array) config('horizon.environments', []) as $pattern => $plan) {
            if (Str::is((string) $pattern, app()->environment())) {
                foreach ((array) $plan as $name => $options) {
                    $supervisors[$name] = array_replace_recursive((array) ($defaults[$name] ?? []), (array) $options);
                }

                break;
            }
        }

        // Sized from the live plan, not from this process's boot-time config:
        // a web request that just enabled a package booted before it did.
        if (isset($supervisors['supervisor-extensions-long'])) {
            $supervisors['supervisor-extensions-long']['processes'] = $this->extensionQueues->longLaneProcesses();
        }

        return array_filter(
            $supervisors,
            fn (array $options): bool => (int) ($options['processes'] ?? 0) > 0
                || (int) ($options['maxProcesses'] ?? 0) > 0,
        );
    }
}
