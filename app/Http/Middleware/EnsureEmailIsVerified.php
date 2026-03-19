<?php

namespace Everest\Http\Middleware;

use Closure;
use Everest\Services\Email\EmailVerificationGate;
use Everest\Services\Email\EmailManager;
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
        return EmailManager::isDeliveryEnabled();
    }
}
