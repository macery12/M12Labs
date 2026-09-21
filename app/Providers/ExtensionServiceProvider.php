<?php

namespace Everest\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Everest\Services\Extensions\ExtensionQueueJournal;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Services\Extensions\ExtensionHookDispatcher;
use Everest\Services\Extensions\ExtensionJobDrainService;
use Everest\Services\Extensions\ExtensionBindingRegistrar;
use Everest\Services\Extensions\ExtensionPermissionRegistry;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

/**
 * Boot-time wiring for the extension platform.
 *
 * Deliberately not a place packages can extend. There is no per-package
 * `register()`/`boot()` hook and there is not meant to be: a service provider
 * runs package code on every request, including the overwhelming majority with
 * nothing to do with that extension, where a route or a job runs only when
 * something reaches it. What packages can do instead is declare container
 * bindings as data — see {@see ExtensionBindingRegistrar}.
 *
 * Deliberately not the owner of extension routes. ExtensionRouteGuardService
 * audits contributed routes by reading RouteFacade::getGroupStack() to work out
 * the middleware they inherit, and `route:cache` bakes that verdict in;
 * registering routes from a provider would run them outside the group stack and
 * break both.
 *
 * The queue wiring is here instead. It hangs off the framework's own job events
 * rather than off methods on ExtensionJob, so a package cannot avoid being
 * recorded, quota-checked or drained by overriding something.
 */
class ExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExtensionQueueRegistry::class);
        $this->app->singleton(ExtensionQueueJournal::class);
        $this->app->singleton(ExtensionJobDrainService::class);
        $this->app->singleton(ExtensionOperationLockService::class);
        $this->app->singleton(ExtensionHookDispatcher::class);
        $this->app->singleton(ExtensionPermissionRegistry::class);
    }

    public function boot(): void
    {
        $journal = $this->app->make(ExtensionQueueJournal::class);

        Event::listen(JobQueueing::class, fn (JobQueueing $event) => $journal->queueing($event));
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $journal->processing($event));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $journal->processed($event));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $journal->failed($event));

        // Extension migrations execute in-process. Renew and fence on both
        // sides of each migration so a multi-file batch cannot outlive its
        // lifecycle owner between schema mutations.
        Event::listen(MigrationStarted::class, fn () => $this->app->make(ExtensionOperationLockService::class)->checkpoint());
        Event::listen(MigrationEnded::class, fn () => $this->app->make(ExtensionOperationLockService::class)->checkpoint());

        // Deferred to booted() for the same reason QueueServiceProvider defers
        // its supervisor sizing: both of these read the runtime plan, which
        // needs the database and the settings the SettingsServiceProvider
        // writes into config during its own boot.
        //
        // Late enough is still early enough. Nothing resolves a package's
        // classes before the router dispatches, and `singleton()` only records
        // a name — the class is not autoloaded until something asks for it.
        $this->app->booted(function (): void {
            $this->configureExtensionQueues();
            $this->app->make(ExtensionBindingRegistrar::class)->register();
        });
    }

    /**
     * Everything the queue needs to know about the currently enabled packages:
     * one named limiter per declared queue group, and whether the long lane has
     * anything that could reach it.
     *
     * One pass over the runtime plan for both. The plan is a live database read
     * by design — it is never cached, so that disabling an extension takes
     * effect in every process at once — which is reason enough not to walk it
     * twice per request.
     *
     * Limiters are registered only for extensions in the plan: one for a
     * disabled extension would never be consulted, and the enabled gate
     * discards its jobs before the limiter would matter anyway.
     */
    private function configureExtensionQueues(): void
    {
        try {
            $queues = $this->app->make(ExtensionQueueRegistry::class)->all();
        } catch (\Throwable) {
            // Console commands run before migrations exist; an install that
            // cannot read the plan registers no limiters and staffs no long
            // worker, which is the correct answer to "we cannot tell".
            return;
        }

        $long = false;

        foreach ($queues as ['id' => $extensionId, 'queue' => $queue]) {
            /** @var QueueDefinition $queue */
            $long = $long || $queue->longRunning;

            $parsed = $queue->parsedRateLimit();

            if ($parsed === null) {
                continue;
            }

            [$count, $perSeconds] = $parsed;

            RateLimiter::for(
                $queue->limiterName($extensionId),
                fn () => Limit::perSecond($count, max(1, (int) ceil($perSeconds)))
            );
        }

        // Staff the long lane only while something can reach it. Sized here
        // rather than in QueueServiceProvider because this is where the plan
        // has already been read; Horizon takes its provisioning plan when the
        // command runs, so a `booted` callback is in time. Installing the first
        // long-running package therefore needs a Horizon restart — the same
        // restart config/queue.php already documents for the extensions lane.
        config([
            'extensions.queues.long_lane_in_use' => $long,
            'horizon.defaults.supervisor-extensions-long.processes' => $long ? 1 : 0,
        ]);
    }
}
