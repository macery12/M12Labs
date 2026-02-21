<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Models\UserSession;
use Everest\Services\Auth\UserSessionService;
use Everest\Transformers\Api\Client\UserSessionTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SessionController extends ClientApiController
{
    public function __construct(private UserSessionService $service)
    {
        parent::__construct();
    }

    /**
     * List sessions for the authenticated user.
     */
    public function index(ClientApiRequest $request): array
    {
        $sessions = $request->user()->sessions()
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->get();

        return $this->fractal->collection($sessions)
            ->transformWith(UserSessionTransformer::class)
            ->toArray();
    }

    /**
     * Revoke a single session.
     */
    public function revoke(ClientApiRequest $request, UserSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $this->service->revokeSession($request->user(), $session);

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Revoke all sessions for the user.
     */
    public function revokeAll(ClientApiRequest $request): JsonResponse
    {
        $includeCurrent = $request->boolean('include_current', false);
        $except = null;

        if (!$includeCurrent && $request->hasSession()) {
            $except = $request->session()->getId();
        }

        $this->service->revokeAll($request->user(), $except);

        if ($includeCurrent && $request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
