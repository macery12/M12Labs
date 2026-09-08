<?php

namespace Everest\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\RateLimiter;
use Everest\Services\Extensions\ExtensionQueueJournal;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

/**
 * Boot-time wiring for the extension platform.
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
    }

    public function boot(): void
    {
        $journal = $this->app->make(ExtensionQueueJournal::class);

        Event::listen(JobQueueing::class, fn (JobQueueing $event) => $journal->queueing($event));
        Event::listen(JobQueued::class, fn (JobQueued $event) => $journal->queued($event));
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $journal->processing($event));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $journal->processed($event));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $journal->failed($event));

        // Deferred to booted() for the same reason QueueServiceProvider defers
        // its supervisor sizing: this reads the runtime plan, which needs the
        // database and the settings the SettingsServiceProvider writes into
        // config during its own boot.
        $this->app->booted(function (): void {
            $this->registerQueueLimiters();
        });
    }

    /**
     * One named limiter per declared queue group, consumed by the RateLimited
     * middleware ExtensionJob attaches.
     *
     * Registered only for extensions in the runtime plan: a limiter for a
     * disabled extension would never be consulted, and the enabled gate
     * discards its jobs before the limiter would matter anyway.
     */
    private function registerQueueLimiters(): void
    {
        try {
            $queues = $this->app->make(ExtensionQueueRegistry::class)->all();
        } catch (\Throwable) {
            // Console commands run before migrations exist; an install that
            // cannot read the plan simply registers no extension limiters.
            return;
        }

        foreach ($queues as ['id' => $extensionId, 'queue' => $queue]) {
            /** @var QueueDefinition $queue */
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
    }
}
