<?php

namespace Everest\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BillingEnabled
{
    /**
     * Handle an incoming request.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!config('modules.billing.enabled')) {
            throw new AccessDeniedHttpException();
        }

        return $next($request);
    }
}
