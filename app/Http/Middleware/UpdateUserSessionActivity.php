<?php

namespace Everest\Http\Middleware;

use Closure;
use Everest\Services\Auth\UserSessionService;
use Everest\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session as SessionFacade;

class UpdateUserSessionActivity
{
    /**
     * Update the last activity timestamp for the authenticated user's session.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->hasSession()) {
            $sessionId = $request->session()->getId();

            $sessionRecord = UserSession::query()
                ->where('user_id', $request->user()->id)
                ->where('session_id', $sessionId)
                ->first();

            if (!$sessionRecord) {
                $payload = SessionFacade::getHandler()->read($sessionId);
                Log::warning('UpdateUserSessionActivity: no user_session record found for active session (skipping logout)', [
                    'user_id' => $request->user()->id,
                    'session_id' => $sessionId,
                    'has_payload' => !empty($payload),
                ]);
            } elseif ($sessionRecord->revoked_at) {
                Log::info('UpdateUserSessionActivity: blocked revoked session', [
                    'user_id' => $request->user()->id,
                    'session_id' => $sessionId,
                ]);

                $guard = auth()->guard();
                if (method_exists($guard, 'logout')) {
                    $guard->logout();
                }
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    return response()->json([
                        'errors' => [[
                            'code' => 'SESSION_REVOKED',
                            'detail' => 'This session has been revoked.',
                        ]],
                    ], 401);
                }

                return redirect()->guest(route('auth.login'));
            }
        }

        $response = $next($request);

        if ($request->user() && $request->hasSession()) {
            $sessionId = $request->session()->getId();
            /** @var UserSessionService $service */
            $service = app(UserSessionService::class);
            $service->updateActivity($request->user(), $sessionId);
        }

        return $response;
    }
}
