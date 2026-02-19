<?php

namespace Everest\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Everest\Models\Setting;

class EnsureEmailIsVerified
{
    /**
     * Ensure the authenticated user's email is verified.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$this->emailSendingEnabled()) {
            return $next($request);
        }

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

    private function emailSendingEnabled(): bool
    {
        $value = strtolower((string) Setting::get('settings::modules:email:resend:enabled', '0'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
