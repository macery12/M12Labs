<?php

namespace Everest\Http\Middleware;

use Closure;
use Everest\Models\Setting;
use Everest\Services\Email\EmailVerificationGate;
use Illuminate\Http\Request;

class EnsureEmailIsVerified
{
    public function __construct(private EmailVerificationGate $gate)
    {
    }

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

        return $this->gate->denyResponse($request);
    }

    private function emailSendingEnabled(): bool
    {
        $value = strtolower((string) Setting::get('settings::modules:email:resend:enabled', '0'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
