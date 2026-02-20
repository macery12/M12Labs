<?php

namespace Everest\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiDocsAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('api-docs.enabled')) {
            abort(404);
        }

        if (config('api-docs.admin_only')) {
            $user = $request->user();

            if (!$user) {
                abort(401);
            }

            if (!$user->root_admin && empty($user->admin_role_id)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
