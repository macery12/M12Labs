<?php

namespace Everest\Http\Middleware\Api\Application\Extensions;

use Illuminate\Http\Request;
use Everest\Models\ExtensionConfig;
use Symfony\Component\HttpFoundation\Response;
use Everest\Services\Extensions\ExtensionRuntimePlanService;

/**
 * Gates extension-contributed admin API routes (routes/admin.php inside a
 * package). Admin authentication itself is inherited from the application-api
 * middleware stack that wraps the whole route file; this middleware only adds
 * the extension-level checks: module on, package installed, config enabled.
 */
class EnsureExtensionAdminAccess
{
    public function __construct(private ExtensionRuntimePlanService $plan)
    {
    }

    public function handle(Request $request, \Closure $next, string $extensionId): Response
    {
        if (!config('modules.extensions.enabled')) {
            return response('', 404);
        }

        // A cached route table and a long-lived application worker can both
        // outlive the package state they were built under. A package must still
        // be executable and still declare admin routes at request time.
        $entry = $this->plan->entry($extensionId);
        if ($entry === null || !$entry->capabilities->adminRoutes) {
            return response('', 404);
        }

        $config = ExtensionConfig::getByExtensionId($extensionId);
        if (!$config || !$config->enabled) {
            return response('', 404);
        }

        return $next($request);
    }
}
