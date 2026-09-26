<?php

namespace Everest\Tests\Integration\Api\Client\Server\Mods;

use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Everest\Models\DownloadQueue;
use Illuminate\Support\Facades\Queue;
use Everest\Services\Mods\CurseForgeService;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

/**
 * The marketplace's admin switches used to be enforced only by the frontend
 * hiding the tab: mod search answered with the module off, and a modpack
 * install needed nothing but a stored CurseForge key.
 */
class MarketplaceGateTest extends ClientApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function marketplaceRoutes(): array
    {
        return [
            'mod search' => ['GET', '/mods/search'],
            'mod details' => ['GET', '/mods/abc123'],
            'mod files' => ['GET', '/mods/abc123/files'],
            'mod download' => ['POST', '/mods/abc123/files/def456/download'],
            'queue' => ['GET', '/mods/queue'],
            'modpack search' => ['GET', '/mods/modpacks/search'],
            'modpack install' => ['POST', '/mods/modpacks/1/versions/2/install'],
        ];
    }

    #[DataProvider('marketplaceRoutes')]
    public function testMarketplaceRoutesAreGoneWhileTheModuleIsOff(string $method, string $path): void
    {
        $this->configure(mods: false, curseforge: true);
        [$user, $server] = $this->generateTestAccount();

        $this->actingAs($user)
            ->json($method, "/api/client/servers/$server->uuid$path")
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * The AI extension's diagnostics read the detected version and loader
     * from here, so switching the marketplace off must not take it away.
     */
    public function testServerConfigStaysReachableWhileTheModuleIsOff(): void
    {
        $this->configure(mods: false, curseforge: false);
        [$user, $server] = $this->generateTestAccount();

        $this->actingAs($user)
            ->getJson("/api/client/servers/$server->uuid/mods/server-config")
            ->assertOk()
            ->assertJsonStructure(['detectedVersion', 'detectedLoader', 'detectedPlatform']);
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function curseForgeOff(): array
    {
        return [
            'switched off' => [false, true],
            'no api key' => [true, false],
        ];
    }

    #[DataProvider('curseForgeOff')]
    public function testModpackRoutesNeedCurseForgeOnAndConfigured(bool $enabled, bool $configured): void
    {
        $this->configure(mods: true, curseforge: $enabled, key: $configured);
        [$user, $server] = $this->generateTestAccount();

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/modpacks/1/versions/2/install", [
                'project_id' => 1,
                'file_id' => 2,
            ])
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * The control for the tests above: with every switch on, the gate lets the
     * request through to the controller.
     */
    public function testModpackRoutesAnswerWithEverySwitchOn(): void
    {
        $this->configure(mods: true, curseforge: true);
        $curseForge = \Mockery::mock(CurseForgeService::class);
        $curseForge->expects('getMinecraftVersions')->andReturn(['1.21.1']);
        $this->app->instance(CurseForgeService::class, $curseForge);

        [$user, $server] = $this->generateTestAccount();

        $this->actingAs($user)
            ->getJson("/api/client/servers/$server->uuid/mods/modpacks/minecraft-versions")
            ->assertOk()
            ->assertJsonPath('data.0', '1.21.1');
    }

    /**
     * The queue routes only need the mods switch, so a modpack retry has to
     * check CurseForge itself — otherwise it re-runs an install the admin
     * switched off.
     */
    public function testModpackRetryIsRefusedWhileCurseForgeIsOff(): void
    {
        $this->configure(mods: true, curseforge: false);
        [$user, $server] = $this->generateTestAccount();
        $item = $this->failedItem($server->id, 'modpack');

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/queue/$item->uuid/retry")
            ->assertStatus(Response::HTTP_NOT_FOUND);

        Queue::assertNothingPushed();
    }

    public function testModRetryOnlyNeedsTheModsSwitch(): void
    {
        $this->configure(mods: true, curseforge: false);
        [$user, $server] = $this->generateTestAccount();
        $item = $this->failedItem($server->id, 'mod');

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/queue/$item->uuid/retry")
            ->assertOk();
    }

    private function configure(bool $mods, bool $curseforge, bool $key = true): void
    {
        config()->set('modules.mods.enabled', $mods);
        config()->set('modules.mods.curseforge_enabled', $curseforge);
        // SettingsServiceProvider hydrates the key as a has-a-value boolean.
        config()->set('modules.mods.curseforge_api_key', $key);
    }

    private function failedItem(int $serverId, string $source): DownloadQueue
    {
        return DownloadQueue::create([
            'uuid' => Str::uuid()->toString(),
            'server_id' => $serverId,
            'provider' => $source === 'modpack' ? 'curseforge' : 'modrinth',
            'source' => $source,
            'project_id' => '1',
            'file_id' => '2',
            'status' => DownloadQueue::STATUS_FAILED,
        ]);
    }
}
