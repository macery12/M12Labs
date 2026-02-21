<?php

namespace Everest\Http\Middleware;

use Closure;
use Everest\Services\Auth\UserSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateUserSessionActivity
{
    /**
     * Update the last activity timestamp for the authenticated user's session.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->user() && $request->hasSession()) {
            $sessionId = $request->session()->getId();
            /** @var UserSessionService $service */
            $service = app(UserSessionService::class);
            $service->updateActivity($request->user(), $sessionId);
            Log::debug('UpdateUserSessionActivity: updated activity', [
                'user_id' => $request->user()->id,
                'session_id' => $sessionId,
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}
