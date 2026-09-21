<?php

namespace Everest\Tests\Unit\Http\Controllers\Api\Application;

use Everest\Tests\TestCase;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Everest\Services\Mods\ModrinthService;
use Everest\Http\Controllers\Api\Application\ModsController;
use Everest\Contracts\Repository\SettingsRepositoryInterface;
use Everest\Http\Controllers\Api\Application\PluginsController;
use Everest\Http\Requests\Api\Application\Mods\UpdateModsSettingsRequest;

class SensitiveSettingsLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    public function testPluginsSettingsActivityLogsUpdate(): void
    {
        $repository = \Mockery::mock(SettingsRepositoryInterface::class);
        $repository->shouldReceive('set')->once();
        $this->app->instance(SettingsRepositoryInterface::class, $repository);
        Artisan::shouldReceive('call')->once()->with('config:clear');

        $controller = new PluginsController(
            \Mockery::mock(ModrinthService::class)
        );

        $request = \Mockery::mock(UpdateModsSettingsRequest::class);
        $request->shouldReceive('normalize')->once()->andReturn([
            'enabled' => true,
        ]);
        $request->shouldReceive('all')->once()->andReturn([
            'enabled' => true,
        ]);

        Activity::shouldReceive('event')->once()->with('admin:plugins:update')->andReturnSelf();
        Activity::shouldReceive('property')
            ->once()
            ->with('settings', \Mockery::on(function (array $payload) {
                return $payload['enabled'] === true;
            }))
            ->andReturnSelf();
        Activity::shouldReceive('description')->once()->andReturnSelf();
        Activity::shouldReceive('log')->once()->andReturnNull();

        $response = $controller->update($request);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function testModsSettingsActivityLogsUpdate(): void
    {
        $repository = \Mockery::mock(SettingsRepositoryInterface::class);
        $repository->shouldReceive('set')->once();
        $this->app->instance(SettingsRepositoryInterface::class, $repository);
        Artisan::shouldReceive('call')->once()->with('config:clear');

        $controller = new ModsController(
            \Mockery::mock(ModrinthService::class)
        );

        $request = \Mockery::mock(UpdateModsSettingsRequest::class);
        $request->shouldReceive('normalize')->once()->andReturn([
            'enabled' => true,
        ]);
        $request->shouldReceive('all')->once()->andReturn([
            'enabled' => true,
        ]);

        Activity::shouldReceive('event')->once()->with('admin:mods:update')->andReturnSelf();
        Activity::shouldReceive('property')
            ->once()
            ->with('settings', \Mockery::on(function (array $payload) {
                return $payload['enabled'] === true;
            }))
            ->andReturnSelf();
        Activity::shouldReceive('description')->once()->andReturnSelf();
        Activity::shouldReceive('log')->once()->andReturnNull();

        $response = $controller->update($request);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }
}
