<?php

namespace Everest\Services\Access;

use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Routing\Route;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Request as RequestFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Everest\Services\Activity\ActivityLogTargetableService;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Everest\Exceptions\Service\Access\InternalDispatchException;

/**
 * Runs a request through the panel's real HTTP pipeline, as the user already
 * authenticated on the parent request.
 *
 * This is the mechanism that lets a caller reuse the panel's authorization
 * instead of growing a second copy of it: an internal request traverses the
 * exact middleware a browser request does — `AuthenticateServerAccess`,
 * `ResourceBelongsToServer`, the endpoint's FormRequest `permission()` gate and
 * its validation — so there is no parallel path to drift out of step.
 *
 * It is also, for the same reason, a confused-deputy generator, which is why an
 * extension reaches it only through `capabilities.privileged` and only after an
 * administrator has approved that at install.
 *
 * Six constraints, each a real failure this has already had:
 *
 * 1. **Send no cookies.** Re-sending already-decrypted ones makes EncryptCookies
 *    fail, nulling the session cookie and regenerating the id on the *shared*
 *    store, which logs the user out mid-request. Auth propagates anyway: the
 *    guard has already cached the user.
 * 2. **Clear the matched route's cached controller.** Routes are process-wide and
 *    `Route::getController()` memoises onto them, so Fractal's accumulating
 *    `parseIncludes()` would leak one call's `?include=` into later ones.
 * 3. **Refuse streamed and binary responses.** Reading their bodies means sending
 *    them, straight into whatever the caller has open.
 * 4. **Send `Accept: application/json`**, or a ValidationException becomes a 302
 *    and an HttpException renders HTML instead of a structured envelope.
 * 5. **Never dispatch inside a transaction.** The exception handler rolls back to
 *    level 0 when it renders, taking the caller's transaction with it.
 * 6. **Bound how long it may block.** Inherited node timeouts run to a quarter of
 *    an hour; callers that mean to return to a human need less than that.
 */
class InternalDispatch
{
    /**
     * Marker placed on an internal request's attribute bag to identify it as
     * internal traffic.
     *
     * An object rather than a string on purpose. `Request::$attributes` is a
     * server-side bag that neither `createFromGlobals()` nor `createFromBase()`
     * ever populates from the wire, so there is no header, query parameter, or
     * body field an external caller could set to land a value in it. Comparing
     * by object identity means that even if something could write to the bag, it
     * could not produce *this instance*.
     *
     * Private, and it stays private: the rate limiters and the exception handler
     * read the marker to treat internal traffic differently, so anything able to
     * mint one could opt itself into that treatment.
     */
    private const ATTRIBUTE = 'everest.internal_dispatch';

    /**
     * Which extension asked, when one did. Read by the rate limiters so a
     * chatty package cannot spend the budget another package — or core's own
     * agent — is also spending.
     */
    private const ORIGIN = 'everest.internal_dispatch.origin';

    private static ?\stdClass $marker = null;

    public function __construct(
        private Application $app,
        private DatabaseManager $db,
    ) {
    }

    /**
     * Whether a request is internal traffic.
     *
     * Read by the rate limiters, which give internal calls their own bounded
     * budget rather than letting one agent turn exhaust the allowance the
     * human's browser session is also spending, and by the exception handler.
     */
    public static function isInternal(Request $request): bool
    {
        return self::$marker !== null
            && $request->attributes->get(self::ATTRIBUTE) === self::$marker;
    }

    /**
     * The extension an internal request was dispatched on behalf of, or null
     * for core's own traffic. Meaningless unless {@see isInternal()} — the
     * attribute only ever arrives beside the marker.
     */
    public static function originOf(Request $request): ?string
    {
        if (!self::isInternal($request)) {
            return null;
        }

        $origin = $request->attributes->get(self::ORIGIN);

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Dispatch one internal request and hand back the response.
     *
     * Two bounds, because they answer different questions. `$deadlineSeconds`
     * is how long the whole call may take before a signal alarm interrupts it —
     * usually what is left of the caller's own budget. `$nodeTimeoutSeconds` is
     * how long any single call out to a node may block, which is smaller and
     * per-call; it clamps the node HTTP timeouts *down* for the duration, never
     * up, because an operator who tightened `GUZZLE_TIMEOUT` meant it. Omitting
     * the second uses the first. Null for both means "whatever this deployment
     * is configured for", which suits a caller with nobody waiting.
     *
     * @throws InternalDispatchException
     */
    public function dispatch(
        InternalRequest $request,
        ?int $deadlineSeconds = null,
        ?int $nodeTimeoutSeconds = null,
        ?string $onBehalfOf = null,
    ): Response {
        if ($this->db->transactionLevel() > 0) {
            throw InternalDispatchException::insideTransaction();
        }

        $parentRequest = $this->app->make('request');
        $parentRoute = $this->app->resolved(Route::class) ? $this->app->make(Route::class) : null;
        $obLevel = ob_get_level();

        $target = $this->app->make(ActivityLogTargetableService::class);
        $snapshot = [$target->actor(), $target->subject(), $target->apiKeyId(), $target->isAdmin()];

        $timeouts = $this->clampNodeTimeouts($nodeTimeoutSeconds ?? $deadlineSeconds);
        $sub = $this->buildSubRequest($request, $parentRequest, $onBehalfOf);
        $matched = null;
        $alarm = $this->startDeadlineAlarm($deadlineSeconds);

        try {
            Activity::reset();

            $response = $this->app->make(HttpKernelContract::class)->handle($sub);
            $matched = $sub->route();

            return $this->assertReadable($response);
        } finally {
            $this->restoreDeadlineAlarm($alarm);

            if ($matched instanceof Route) {
                $matched->controller = null;
            }

            config($timeouts);

            Activity::reset();
            $this->restoreLogTarget($target, $snapshot);

            // Re-binding `request` fires the container rebound hooks, which is
            // what restores the auth guards, the URL generator, and the user
            // resolver — none of those need handling individually.
            $this->app->instance('request', $parentRequest);
            RequestFacade::clearResolvedInstance();

            if ($parentRoute !== null) {
                $this->app->instance(Route::class, $parentRoute);
            } else {
                $this->app->forgetInstance(Route::class);
            }

            while (ob_get_level() > $obLevel) {
                ob_end_flush();
            }
        }
    }

    /**
     * Refuse a response whose body can only be read by sending it.
     *
     * The kernel contract documents a narrower return type than it has: a
     * controller returning a download or an event stream produces a
     * `BinaryFileResponse` or a `StreamedResponse`, and `getContent()` is false
     * on both. Sending one writes into whatever the caller has open — for the
     * agent, a live SSE stream — so the endpoint is refused instead. Typed on
     * the Symfony base for exactly that reason.
     */
    private function assertReadable(Response $response): Response
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            throw InternalDispatchException::unreadableResponse();
        }

        return $response;
    }

    /**
     * Hold the node timeouts down for one call, returning what they were so the
     * `finally` can restore them.
     *
     * Config rather than a parameter because the value must reach a repository
     * several layers into the sub-request.
     *
     * @return array<string, int> the previous values, shaped for `config()`
     */
    private function clampNodeTimeouts(?int $seconds): array
    {
        $previous = [
            'everest.guzzle.timeout' => (int) config('everest.guzzle.timeout'),
            'everest.guzzle.archive_timeout' => (int) config('everest.guzzle.archive_timeout'),
        ];

        if ($seconds === null) {
            return $previous;
        }

        $ceiling = max(1, $seconds);

        config([
            'everest.guzzle.timeout' => min($previous['everest.guzzle.timeout'], $ceiling),
            'everest.guzzle.archive_timeout' => min($previous['everest.guzzle.archive_timeout'], $ceiling),
        ]);

        return $previous;
    }

    /**
     * Bound local controller and database work as well as node HTTP calls.
     * PCNTL alarms interrupt the synchronous kernel dispatch; deployments
     * without PCNTL retain their configured PHP execution limit.
     *
     * @return array{handler: mixed, async: bool}|null
     */
    private function startDeadlineAlarm(?int $seconds): ?array
    {
        if (
            $seconds === null
            || $seconds < 1
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_async_signals')
        ) {
            return null;
        }

        $state = [
            'handler' => pcntl_signal_get_handler(SIGALRM),
            'async' => pcntl_async_signals(true),
        ];

        pcntl_signal(SIGALRM, static function (): void {
            throw InternalDispatchException::deadlineElapsed();
        });
        pcntl_alarm($seconds);

        return $state;
    }

    /** @param array{handler: mixed, async: bool}|null $state */
    private function restoreDeadlineAlarm(?array $state): void
    {
        if ($state === null) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $state['handler']);
        pcntl_async_signals($state['async']);
    }

    /**
     * Build the sub-request. Carries no `Cookie`, `Authorization`, `Referer` or
     * `Origin` header: without a session cookie the stateful path is skipped, so
     * CSRF and session handling never run, while the guard's cached user keeps
     * the identity and token instance identical. Fails closed — a cold cache
     * 401s rather than escalating.
     */
    private function buildSubRequest(InternalRequest $request, Request $parent, ?string $onBehalfOf = null): Request
    {
        $isRead = $request->isRead();

        $sub = Request::create(
            // Absolute so the URL generator stays correct after the rebind —
            // signed node URLs for downloads depend on it.
            uri: $parent->getSchemeAndHttpHost() . $request->fullUri(),
            method: strtoupper($request->method),
            parameters: $isRead ? $request->query : [],
            cookies: [],
            files: [],
            server: ['REMOTE_ADDR' => $parent->ip()],
            content: $isRead ? null : json_encode($request->body ?: new \stdClass()),
        );

        // Mandatory: it is what makes the exception handler emit the JSON
        // envelope instead of a redirect or an HTML error page.
        $sub->headers->set('Accept', 'application/json');
        $sub->headers->set('X-Requested-With', 'XMLHttpRequest');

        if ($request->idempotencyKey !== null) {
            $sub->headers->set('Idempotency-Key', $request->idempotencyKey);
        }

        if (!$isRead) {
            $sub->headers->set('Content-Type', 'application/json');
        }

        foreach (['Cookie', 'Authorization', 'Referer', 'Origin', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN'] as $header) {
            $sub->headers->remove($header);
        }

        $sub->attributes->set(self::ATTRIBUTE, self::$marker ??= new \stdClass());
        if ($onBehalfOf !== null) {
            $sub->attributes->set(self::ORIGIN, $onBehalfOf);
        }
        $sub->setUserResolver($parent->getUserResolver());

        return $sub;
    }

    /**
     * `setIsAdmin()` is write-only-true, so the flag can only be restored by
     * resetting first and re-applying.
     *
     * @param array{0: mixed, 1: mixed, 2: mixed, 3: bool} $snapshot
     */
    private function restoreLogTarget(ActivityLogTargetableService $target, array $snapshot): void
    {
        [$actor, $subject, $apiKeyId, $isAdmin] = $snapshot;

        $target->reset();

        if ($actor !== null) {
            $target->setActor($actor);
        }
        if ($subject !== null) {
            $target->setSubject($subject);
        }
        $target->setApiKeyId($apiKeyId);
        if ($isAdmin) {
            $target->setIsAdmin();
        }
    }
}
