<?php

namespace Everest\Http\Middleware;

use Closure;
use Everest\Services\Email\EmailVerificationGate;
use Illuminate\Http\Request;

class EnsureVerifiedCanViewArea
{
    public function __construct(private EmailVerificationGate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string $area)
    {
        if ($this->gate->canViewArea($request->user(), $area)) {
            return $next($request);
        }

        return $this->gate->denyResponse($request);
    }
}
