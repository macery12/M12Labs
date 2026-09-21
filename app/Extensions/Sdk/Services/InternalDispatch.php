<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Services\Access\InternalRequest;
use Everest\Extensions\Sdk\Http\InternalResponse;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Exceptions\Service\Access\InternalDispatchException;
use Everest\Services\Access\InternalDispatch as CoreInternalDispatch;
use Everest\Exceptions\Service\Extension\PrivilegeNotGrantedException;

/**
 * Call the panel's own API as the user whose request you are already handling.
 *
 * This is how a package does something the panel already knows how to do
 * without rebuilding the authorization for it. An internal request traverses
 * the exact middleware a browser request does — `AuthenticateServerAccess`,
 * `ResourceBelongsToServer`, the endpoint's own permission gate and its
 * validation — so a package cannot accidentally reach past what the person in
 * front of it is allowed to do, and does not have to get that right itself.
 *
 * Requires `internal_dispatch` under `capabilities.privileged`. It is on that
 * list because acting with somebody else's authority is exactly the shape of
 * mistake worth an administrator seeing before they install: a package that
 * dispatches as the current user can, through a bug, do anything that user
 * could. The grant is consent, not containment — package PHP is trusted,
 * reviewed code and nothing here sandboxes it.
 *
 * Two things to know before using it:
 *
 * - **Not inside a transaction.** The panel's exception handler rolls back to
 *   level 0 when it renders, which would take your transaction with it. The
 *   call refuses rather than risking it.
 * - **Not for downloads or streams.** Reading such a response means sending it,
 *   into whatever the caller has open. Those endpoints refuse too.
 *
 * Calls are rate limited per extension and per user, so a chatty package
 * cannot spend the budget another package — or a person's own browser session
 * — is also spending.
 *
 * ```php
 * $api = InternalDispatch::for('my_extension');
 *
 * $files = $api->get("/api/client/servers/{$server->uuidShort}/files/list", ['directory' => '/']);
 * if ($files->failed()) {
 *     throw new DisplayException($files->errorDetail() ?? 'The file list could not be read.');
 * }
 * ```
 */
final class InternalDispatch
{
    public const PRIVILEGE = 'internal_dispatch';

    private function __construct(
        private string $extensionId,
        private CoreInternalDispatch $dispatch,
        private ExtensionRuntimePlanService $plan,
    ) {
    }

    /**
     * @throws PrivilegeNotGrantedException when the live runtime plan does not grant it
     */
    public static function for(string $extensionId): self
    {
        $plan = app(ExtensionRuntimePlanService::class);

        if (!$plan->grantsPrivilege($extensionId, self::PRIVILEGE)) {
            throw new PrivilegeNotGrantedException($extensionId, self::PRIVILEGE);
        }

        return new self($extensionId, app(CoreInternalDispatch::class), $plan);
    }

    /**
     * @param array<string, mixed> $query
     * @param int|null $nodeSeconds ceiling for a single daemon call made while
     *                              serving this sub-request; see {@see send()}
     */
    public function get(string $uri, array $query = [], ?int $maxSeconds = null, ?int $nodeSeconds = null): InternalResponse
    {
        return $this->send(new InternalRequest('GET', $uri, query: $query), $maxSeconds, $nodeSeconds);
    }

    /** @param array<string, mixed> $body */
    public function post(string $uri, array $body = [], ?string $idempotencyKey = null, ?int $maxSeconds = null, ?int $nodeSeconds = null): InternalResponse
    {
        return $this->send(new InternalRequest('POST', $uri, body: $body, idempotencyKey: $idempotencyKey), $maxSeconds, $nodeSeconds);
    }

    /** @param array<string, mixed> $body */
    public function put(string $uri, array $body = [], ?string $idempotencyKey = null, ?int $maxSeconds = null, ?int $nodeSeconds = null): InternalResponse
    {
        return $this->send(new InternalRequest('PUT', $uri, body: $body, idempotencyKey: $idempotencyKey), $maxSeconds, $nodeSeconds);
    }

    /** @param array<string, mixed> $body */
    public function patch(string $uri, array $body = [], ?string $idempotencyKey = null, ?int $maxSeconds = null, ?int $nodeSeconds = null): InternalResponse
    {
        return $this->send(new InternalRequest('PATCH', $uri, body: $body, idempotencyKey: $idempotencyKey), $maxSeconds, $nodeSeconds);
    }

    /** @param array<string, mixed> $body */
    public function delete(string $uri, array $body = [], ?int $maxSeconds = null, ?int $nodeSeconds = null): InternalResponse
    {
        return $this->send(new InternalRequest('DELETE', $uri, body: $body), $maxSeconds, $nodeSeconds);
    }

    /**
     * Failures come back as a response, not an exception, because every one of
     * them is something the panel itself would have told a browser: a 403, a
     * 404, a 422 with the offending fields. A package handling those is
     * handling ordinary API results, and should not have to catch to do it.
     *
     * The two bounds are separate on purpose. `$maxSeconds` is how long the
     * whole sub-request may take; `$nodeSeconds` is how long one call to a
     * node made while serving it may take. Collapsing them into one number
     * lets a single slow node consume a caller's entire remaining allowance in
     * local controller work, which is what they were split to prevent.
     */
    private function send(InternalRequest $request, ?int $maxSeconds, ?int $nodeSeconds = null): InternalResponse
    {
        $this->assertGranted();

        try {
            $response = $this->dispatch->dispatch(
                $request,
                deadlineSeconds: $maxSeconds,
                nodeTimeoutSeconds: $nodeSeconds,
                onBehalfOf: $this->extensionId,
            );
        } catch (InternalDispatchException $e) {
            return new InternalResponse(
                status: $e->reason === InternalDispatchException::REASON_DEADLINE ? 504 : 500,
                json: null,
                body: $e->getMessage(),
            );
        }

        $body = (string) $response->getContent();
        $decoded = json_decode($body, true);

        return new InternalResponse(
            status: $response->getStatusCode(),
            json: json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
            body: $body,
        );
    }

    /**
     * Recheck immediately before dispatch. A facade can outlive the package
     * state that created it in a queue worker or another long-lived process.
     *
     * @throws PrivilegeNotGrantedException
     */
    private function assertGranted(): void
    {
        if (!$this->plan->grantsPrivilege($this->extensionId, self::PRIVILEGE)) {
            throw new PrivilegeNotGrantedException($this->extensionId, self::PRIVILEGE);
        }
    }
}
