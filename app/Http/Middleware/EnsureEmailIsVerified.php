<?php

namespace Everest\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnsureEmailIsVerified
{
    /**
     * Ensure the authenticated user's email is verified.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $payload = [
            'code' => 'EMAIL_NOT_VERIFIED',
            'message' => 'Please verify your email address to access this feature.',
        ];

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($payload, Response::HTTP_FORBIDDEN);
        }

        return redirect()->to('/account')->with('warning', $payload['message']);
    }
}
