<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Models\Server;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Services\Extensions\ExtensionRuntimeEntry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Http\Middleware\Api\Client\Extensions\EnsureExtensionAccess;
use Everest\Http\Middleware\Api\Application\Extensions\EnsureExtensionAdminAccess;

class ExtensionRuntimeRouteMiddlewareTest extends TestCase
{
    public function testAStaleAdminRouteIsDeniedWhenThePackageIsNoLongerRunnable(): void
    {
        config()->set('modules.extensions.enabled', true);
        $plan = $this->createMock(ExtensionRuntimePlanService::class);
        $plan->expects($this->once())->method('entry')->with('demo')->willReturn(null);

        $response = (new EnsureExtensionAdminAccess($plan))->handle(
            Request::create('/api/application/extensions/ext/demo/action'),
            fn () => response('extension ran'),
            'demo',
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAStaleClientRouteIsDeniedWhenClientRoutesWereRemoved(): void
    {
        $plan = $this->createMock(ExtensionRuntimePlanService::class);
        $plan->expects($this->once())->method('entry')->with('demo')->willReturn(
            new ExtensionRuntimeEntry('demo', '2.0.0', new ExtensionCapabilitySet())
        );

        $request = Request::create('/api/client/servers/example/extensions/ext/demo/action');
        $request->setUserResolver(fn () => new \Everest\Models\User());
        $request->setRouteResolver(fn () => new class (new Server()) {
            public function __construct(private Server $server)
            {
            }

            public function parameter(string $name): mixed
            {
                return $name === 'server' ? $this->server : null;
            }
        });

        $response = (new EnsureExtensionAccess($plan))->handle(
            $request,
            fn () => response('extension ran'),
            'demo',
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
