<?php

namespace Everest\Http\Middleware\Api\Client\Server;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BillingUpgradesEnabled
{
    /**
     * Handle an incoming request.
     *
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!config('modules.billing.allow_upgrades')) {
            throw new AccessDeniedHttpException();
        }

        return $next($request);
    }
}
