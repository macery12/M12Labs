<?php

namespace Everest\Tests\Unit\Http\Middleware\Api\Client\Server;

use Everest\Models\Server;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Everest\Extensions\Packages\fixture_scoped\Models\ScopedThing;
use Everest\Extensions\Packages\fixture_scoped\Models\UnscopedThing;
use Everest\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;

/**
 * The loader applies this middleware to every extension client route, so a
 * package binding its own model depends on it entirely.
 *
 * Before this was handled, an extension model fell to the default branch and
 * raised InvalidArgumentException — a 500 on every request to the route, which
 * is how the custom_domains delete endpoint first behaved.
 */
class ResourceBelongsToServerTest extends TestCase
{
    private function pass(Server $server, string $key, mixed $model): bool
    {
        $route = new Route(['DELETE'], '/{server}/{' . $key . '}', fn () => null);
        $route->parameters = ['server' => $server, $key => $model];

        $request = Request::create('/', 'DELETE');
        $request->setRouteResolver(fn () => $route);

        $reached = false;
        (new ResourceBelongsToServer())->handle($request, function () use (&$reached) {
            $reached = true;

            return null;
        });

        return $reached;
    }

    private function server(int $id): Server
    {
        $server = new Server();
        $server->id = $id;
        $server->exists = true;

        return $server;
    }

    public function testAPackageModelOnItsOwnServerIsAllowedThrough(): void
    {
        $thing = new ScopedThing(['server_id' => 7]);

        $this->assertTrue($this->pass($this->server(7), 'thing', $thing));
    }

    public function testAPackageModelBelongingToAnotherServerIs404ed(): void
    {
        $thing = new ScopedThing(['server_id' => 7]);

        // The IDOR case: a subuser on server 8 naming a mapping id from server 7.
        $this->expectException(NotFoundHttpException::class);
        $this->pass($this->server(8), 'thing', $thing);
    }

    /**
     * A string id has not been resolved to a model, so there is nothing to
     * compare — the binding will 404 later on its own.
     */
    public function testANonModelParameterIsIgnored(): void
    {
        $this->assertTrue($this->pass($this->server(7), 'thing', '7'));
    }

    /**
     * Fail closed rather than guess. A package model with no server_id cannot be
     * scoped, and waving it through is how cross-server access happens.
     */
    public function testAPackageModelWithNoServerIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no server_id to scope it by');
        $this->pass($this->server(7), 'thing', new UnscopedThing(['name' => 'x']));
    }

    /**
     * The fail-safe for core models is unchanged: a resource this middleware was
     * never taught about must not be silently accepted.
     */
    public function testAnUnknownCoreModelStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no handler configured');
        $this->pass($this->server(7), 'thing', new \Everest\Models\Node());
    }
}
