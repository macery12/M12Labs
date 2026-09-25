<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\RouteCollection;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Everest\Services\Extensions\ExtensionRuntimeEntry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Http\Controllers\Api\BlockedExtensionRouteController;
use Everest\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Everest\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

class ClientExtensionRouteSecurityTest extends TestCase
{
    public function testLoaderBoundarySurvivesRegistrationAndRouteCaching(): void
    {
        $root = sys_get_temp_dir() . '/extension-routes-' . bin2hex(random_bytes(8));
        $appPath = $this->app->path();
        $routeFile = base_path('routes/api-client.php');
        mkdir($root . '/Extensions/Packages/demo/routes', 0777, true);
        mkdir($root . '/Extensions/Packages/disabled/routes', 0777, true);
        file_put_contents($root . '/Extensions/Packages/demo/routes/client.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use Everest\Tests\Unit\Services\Extensions\ClientExtensionFixtureController;
use Everest\Http\Middleware\Api\Client\Extensions\EnsureExtensionAccess;
Route::get('/ok', [ClientExtensionFixtureController::class, 'index']);
Route::get('/excluded', [ClientExtensionFixtureController::class, 'index'])->withoutMiddleware('extensions.access:demo');
Route::get('/wrong-id', [ClientExtensionFixtureController::class, 'index'])->middleware('extensions.access:other');
Route::get('/wrong-class-id', [ClientExtensionFixtureController::class, 'index'])->middleware(EnsureExtensionAccess::class . ':other');
$route = Route::get('/substituted', [ClientExtensionFixtureController::class, 'index']);
$action = $route->getAction();
$action['middleware'] = ['extensions.access:other'];
$route->setAction($action);
$route = Route::get('/stripped-auth', [ClientExtensionFixtureController::class, 'index']);
$action = $route->getAction();
$action['middleware'] = ['extensions.access:demo'];
$route->setAction($action);
PHP);
        file_put_contents($root . '/Extensions/Packages/disabled/routes/client.php', '<?php throw new \RuntimeException("Disabled code executed");');

        try {
            $this->app->useAppPath($root);
            $entries = [
                'demo' => new ExtensionRuntimeEntry('demo', '1.0.0', new ExtensionCapabilitySet(clientRoutes: true)),
                // Declares no client routes, so the loader must not require its
                // file even though one exists on disk and throws when included.
                'disabled' => new ExtensionRuntimeEntry('disabled', '1.0.0', new ExtensionCapabilitySet()),
            ];
            $this->app->instance(ExtensionRuntimePlanService::class, new class ($entries) extends ExtensionRuntimePlanService {
                public function __construct(private array $entries)
                {
                }

                public function plan(): array
                {
                    return $this->entries;
                }
            });
            Route::setRoutes(new RouteCollection());
            Route::prefix('api/client')->middleware(['api', 'auth:sanctum', 'throttle:api.client'])->group($routeFile);
            $this->assertBoundary();

            // Use Laravel's actual route-cache format and loader in isolation;
            // never overwrite the running panel's bootstrap/cache routes.
            foreach (Route::getRoutes() as $route) {
                $route->prepareForSerialization();
            }
            $cache = str_replace('{{routes}}', var_export(Route::getRoutes()->compile(), true), file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/routes.stub')));
            file_put_contents($root . '/routes.php', $cache);
            require $root . '/routes.php';
            $this->assertBoundary();
        } finally {
            $this->app->useAppPath($appPath);
            $this->app->forgetInstance(ExtensionRuntimePlanService::class);
            ExtensionRuntimeGate::flush();
            app('files')->deleteDirectory($root);
        }
    }

    private function assertBoundary(): void
    {
        foreach (['ok', 'excluded', 'wrong-id', 'wrong-class-id', 'substituted', 'stripped-auth'] as $path) {
            $route = Route::getRoutes()->match(Request::create('/api/client/servers/example/extensions/ext/demo/' . $path));
            $this->assertSame(
                $path === 'ok' ? ClientExtensionFixtureController::class . '@index' : BlockedExtensionRouteController::class . '@__invoke',
                $route->getAction('uses'),
                $path,
            );
            foreach (['auth:sanctum', 'throttle:api.client', AuthenticateServerAccess::class, ResourceBelongsToServer::class, 'extensions.access:demo', 'throttle:api.ext-client'] as $middleware) {
                $this->assertContains($middleware, $route->middleware(), $path);
            }
            $this->assertSame([], $route->excludedMiddleware());
        }
    }
}

class ClientExtensionFixtureController
{
    public function index(): void
    {
    }

    /**
     * Loading is driven by the declared capability, not by a filesystem glob.
     *
     * A package that ships routes/client.php without declaring
     * capabilities.routes.client is rejected at install; this pins the second
     * line of defence — even if such a package existed on disk, the loader
     * never require()s it, so its top-level code cannot run.
     */
    public function testAPackageThatDoesNotDeclareClientRoutesIsNeverLoaded(): void
    {
        $root = sys_get_temp_dir() . '/extension-undeclared-' . bin2hex(random_bytes(8));
        $appPath = $this->app->path();
        $routeFile = base_path('routes/api-client.php');
        mkdir($root . '/Extensions/Packages/undeclared/routes', 0777, true);
        file_put_contents(
            $root . '/Extensions/Packages/undeclared/routes/client.php',
            '<?php throw new \RuntimeException("Undeclared route file was loaded");'
        );

        try {
            $this->app->useAppPath($root);
            $entries = [
                // Enabled and executable, but declares no client routes.
                'undeclared' => new ExtensionRuntimeEntry('undeclared', '1.0.0', new ExtensionCapabilitySet()),
            ];
            $this->app->instance(ExtensionRuntimePlanService::class, new class ($entries) extends ExtensionRuntimePlanService {
                public function __construct(private array $entries)
                {
                }

                public function plan(): array
                {
                    return $this->entries;
                }
            });
            Route::setRoutes(new RouteCollection());

            Route::prefix('api/client')->middleware(['api', 'auth:sanctum', 'throttle:api.client'])->group($routeFile);

            foreach (Route::getRoutes() as $route) {
                $this->assertStringNotContainsString('ext/undeclared', $route->uri());
            }
        } finally {
            $this->app->useAppPath($appPath);
            $this->app->forgetInstance(ExtensionRuntimePlanService::class);
            ExtensionRuntimeGate::flush();
            app('files')->deleteDirectory($root);
        }
    }
}
