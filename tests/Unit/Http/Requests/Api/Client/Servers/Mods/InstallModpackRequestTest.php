<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Client\Servers\Mods;

use Mockery as m;
use Everest\Models\User;
use Everest\Models\Server;
use Everest\Tests\TestCase;
use Illuminate\Routing\Route;
use Everest\Models\Permission;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Http\Requests\Api\Client\Servers\Mods\InstallModpackRequest;

class InstallModpackRequestTest extends TestCase
{
    public function testExtractionRequiresCreateAndUpdate(): void
    {
        $request = $this->request(['wipe_server' => false], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => false,
        ]);

        $this->assertFalse($request->authorize());
    }

    public function testNonWipingExtractionAllowsCreateAndUpdate(): void
    {
        $request = $this->request(['wipe_server' => false], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => true,
        ]);

        $this->assertTrue($request->authorize());
    }

    public function testWipingExtractionAlsoRequiresDelete(): void
    {
        $request = $this->request(['wipe_server' => true], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => true,
            Permission::ACTION_FILE_DELETE => false,
        ]);

        $this->assertFalse($request->authorize());
    }

    /**
     * The loader step rewrites the server's startup command and Docker image,
     * which file permissions must not reach on their own.
     *
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function missingStartupPermission(): array
    {
        return [
            'no startup.update' => [false, true],
            'no startup.docker-image' => [true, false],
        ];
    }

    #[DataProvider('missingStartupPermission')]
    public function testLoaderInstallRequiresBothStartupPermissions(bool $update, bool $image): void
    {
        $request = $this->request(['install_loader' => true], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => true,
            Permission::ACTION_STARTUP_UPDATE => $update,
            Permission::ACTION_STARTUP_DOCKER_IMAGE => $image,
        ]);

        $this->assertFalse($request->authorize());
    }

    public function testLoaderInstallAllowedWithStartupPermissions(): void
    {
        $request = $this->request(['install_loader' => true], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => true,
            Permission::ACTION_STARTUP_UPDATE => true,
            Permission::ACTION_STARTUP_DOCKER_IMAGE => true,
        ]);

        $this->assertTrue($request->authorize());
    }

    public function testInstallWithoutLoaderNeedsNoStartupPermission(): void
    {
        $request = $this->request(['install_loader' => false], [
            Permission::ACTION_FILE_CREATE => true,
            Permission::ACTION_FILE_UPDATE => true,
            Permission::ACTION_STARTUP_UPDATE => false,
            Permission::ACTION_STARTUP_DOCKER_IMAGE => false,
        ]);

        $this->assertTrue($request->authorize());
    }

    /**
     * @param array<string, bool> $permissions
     */
    private function request(array $input, array $permissions): InstallModpackRequest
    {
        $request = new InstallModpackRequest();
        $request->replace($input);
        $server = new Server();
        $route = m::mock(Route::class);
        $route->allows('parameter')->with('server')->andReturn($server);

        $user = m::mock(User::class);
        foreach ($permissions as $permission => $allowed) {
            $user->allows('can')->with($permission, $server)->andReturn($allowed);
        }

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
