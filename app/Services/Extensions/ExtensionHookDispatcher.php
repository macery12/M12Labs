<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;
use Everest\Extensions\Hooks\HookEvent;
use Everest\Models\ExtensionHookHealth;
use Everest\Extensions\Hooks\HookHandler;
use Everest\Models\ExtensionHookTombstone;
use Everest\Extensions\Jobs\RunExtensionHookJob;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;

/**
 * Delivers a core event to the extensions that declared it.
 *
 * Every safety property here exists because a hook runs core's own code path
 * with a package's code inside it:
 *
 *  - **Resolution is closed.** A handler is loaded only when the manifest
 *    declares it, the event is in core's allowlist, the class sits inside that
 *    package's own Hooks namespace, and it implements HookHandler. Anything
 *    else is reported and skipped. There is no registrar and no package boot
 *    file, so a package cannot subscribe to anything by running code.
 *  - **Isolation is per handler.** One extension throwing never reaches another
 *    and never reaches the caller. A hook has no way to abort the operation
 *    that raised it.
 *  - **A failing hook takes itself out.** Five consecutive failures open a
 *    breaker for fifteen minutes; repeated trips quarantine that hook — that
 *    hook only, not the extension and not another extension's subscription.
 *
 * synchronous_required is not implemented, and the parser rejects it at
 * install. PHP cannot preempt a handler, so a declared timeout is a budget and
 * not an enforcement; a handler able to block a server deletion indefinitely is
 * an availability risk with no bound.
 */
class ExtensionHookDispatcher
{
    /** Consecutive failures before a handler's breaker opens. */
    private const BREAKER_THRESHOLD = 5;

    private const BREAKER_COOLDOWN_MINUTES = 15;

    /** Breaker trips before the handler is set aside entirely. */
    private const QUARANTINE_AFTER_TRIPS = 3;

    public function __construct(private ExtensionRuntimePlanService $plan)
    {
    }

    /**
     * Deliver an event to every declared handler.
     *
     * Returns the number of handlers that were invoked or enqueued, which is
     * what a test asserts on; callers ignore it. With no extensions installed
     * this is a query-free no-op.
     */
    public function dispatch(HookEvent $event): int
    {
        $subscriptions = $this->plan->hooksFor($event->name());

        if ($subscriptions === []) {
            return 0;
        }

        $envelope = $event->envelope();
        $delivered = 0;

        // Tombstones are written for every declared handler before any of them
        // runs, regardless of mode. A queued handler executes after the FK
        // cascade has removed its rows, so the tombstone is the only record of
        // what it was supposed to act on.
        if ($event->name() === 'server.pre_delete') {
            $this->writeTombstones($subscriptions, $envelope);
        }

        foreach ($subscriptions as ['id' => $extensionId, 'hook' => $hook]) {
            /** @var HookDefinition $hook */
            if ($this->isSuppressed($extensionId, $hook)) {
                continue;
            }

            $delivered += $hook->isQueued()
                ? $this->enqueue($extensionId, $hook, $envelope)
                : $this->invokeSynchronously($extensionId, $hook, $envelope);
        }

        return $delivered;
    }

    /**
     * @param array<int, array{id: string, version: string, hook: HookDefinition}> $subscriptions
     * @param array<string, mixed> $envelope
     */
    private function writeTombstones(array $subscriptions, array $envelope): void
    {
        foreach ($subscriptions as ['id' => $extensionId, 'hook' => $hook]) {
            try {
                ExtensionHookTombstone::query()->updateOrCreate(
                    [
                        'correlation_id' => $envelope['correlationId'],
                        'extension_id' => $extensionId,
                        'handler' => $hook->handler,
                    ],
                    [
                        'event' => $envelope['event'],
                        'envelope' => $envelope,
                    ]
                );
            } catch (\Throwable $exception) {
                // A tombstone that cannot be written must not stop the
                // deletion; the synchronous path still works without it.
                report($exception);
            }
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function enqueue(string $extensionId, HookDefinition $hook, array $envelope): int
    {
        try {
            RunExtensionHookJob::dispatch($extensionId, $hook->event, $hook->handler, $envelope);

            return 1;
        } catch (\Throwable $exception) {
            // Dispatch is refused when the extension is draining or over quota.
            // That is a decision, not a fault: record it and move on.
            $this->recordFailure($extensionId, $hook, $exception, 0);

            return 0;
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function invokeSynchronously(string $extensionId, HookDefinition $hook, array $envelope): int
    {
        $handler = $this->resolve($extensionId, $hook);

        if ($handler === null) {
            return 0;
        }

        $started = hrtime(true);

        try {
            $handler->handle($envelope);
            $this->recordSuccess($extensionId, $hook, $this->elapsedMs($started));

            return 1;
        } catch (\Throwable $exception) {
            // The caller is mid-operation and never learns a hook failed. That
            // is the contract: best effort means core proceeds.
            report($exception);
            $this->recordFailure($extensionId, $hook, $exception, $this->elapsedMs($started));

            return 0;
        }
    }

    /**
     * Load a declared handler, or null when it cannot be trusted to run.
     */
    public function resolve(string $extensionId, HookDefinition $hook): ?HookHandler
    {
        $class = $hook->handlerClass($extensionId);

        if (!class_exists($class)) {
            report(new \RuntimeException(sprintf(
                'Extension [%s] declares hook handler [%s], which does not exist.',
                $extensionId,
                $class
            )));

            return null;
        }

        $instance = app($class);

        if (!$instance instanceof HookHandler) {
            report(new \RuntimeException(sprintf(
                'Extension [%s] hook handler [%s] does not implement HookHandler.',
                $extensionId,
                $class
            )));

            return null;
        }

        return $instance;
    }

    /**
     * Whether this handler is currently taken out of delivery.
     *
     * Half-open: once the cooldown passes, one invocation is let through. It
     * either succeeds and clears the breaker, or fails and reopens it.
     */
    private function isSuppressed(string $extensionId, HookDefinition $hook): bool
    {
        $health = $this->health($extensionId, $hook);

        if ($health === null) {
            return false;
        }

        if ($health->quarantined_at !== null) {
            return true;
        }

        return $health->breaker_open_until !== null && $health->breaker_open_until->isFuture();
    }

    public function recordSuccess(string $extensionId, HookDefinition $hook, int $durationMs): void
    {
        $this->write($extensionId, $hook, function (ExtensionHookHealth $health) use ($durationMs): void {
            ++$health->invocations;
            $health->consecutive_failures = 0;
            $health->breaker_open_until = null;
            $health->total_duration_ms += $durationMs;
            $health->last_invoked_at = now();
        });
    }

    public function recordFailure(string $extensionId, HookDefinition $hook, \Throwable $exception, int $durationMs): void
    {
        $this->write($extensionId, $hook, function (ExtensionHookHealth $health) use ($exception, $durationMs): void {
            ++$health->invocations;
            ++$health->failures;
            ++$health->consecutive_failures;
            $health->total_duration_ms += $durationMs;
            $health->last_invoked_at = now();
            $health->last_failed_at = now();
            // Class and message only: a hook payload can carry an extension's
            // own data and this is rendered on an admin page.
            $health->last_error = sprintf('%s: %s', $exception::class, Str::limit($exception->getMessage(), 500));

            if ($health->consecutive_failures >= self::BREAKER_THRESHOLD) {
                $health->consecutive_failures = 0;
                ++$health->breaker_trips;
                $health->breaker_open_until = now()->addMinutes(self::BREAKER_COOLDOWN_MINUTES);

                if ($health->breaker_trips >= self::QUARANTINE_AFTER_TRIPS) {
                    $health->quarantined_at = now();
                }
            }
        });
    }

    private function health(string $extensionId, HookDefinition $hook): ?ExtensionHookHealth
    {
        try {
            return ExtensionHookHealth::query()
                ->where('extension_id', $extensionId)
                ->where('event', $hook->event)
                ->where('handler', $hook->handler)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function write(string $extensionId, HookDefinition $hook, callable $mutate): void
    {
        try {
            $health = ExtensionHookHealth::query()->firstOrNew([
                'extension_id' => $extensionId,
                'event' => $hook->event,
                'handler' => $hook->handler,
            ]);

            $mutate($health);
            $health->save();
        } catch (\Throwable $exception) {
            // Bookkeeping must never be why an operation fails.
            report($exception);
        }
    }

    private function elapsedMs(int $startedAtHrTime): int
    {
        return (int) round((hrtime(true) - $startedAtHrTime) / 1_000_000);
    }
}
