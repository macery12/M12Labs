<?php

namespace Everest\Tests\Integration\Api\Client\Server\Mods;

use Everest\Models\Node;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Everest\Models\Permission;
use Everest\Models\DownloadQueue;
use Everest\Jobs\InstallModpackJob;
use Illuminate\Support\Facades\Queue;
use Everest\Services\Mods\CurseForgeService;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

/**
 * Retrying a failed modpack install used to force the loader step on, so a
 * pack installed onto an existing loader had its startup command and Docker
 * image rewritten the first time anyone pressed Retry.
 */
class ModpackRetryLoaderTest extends ClientApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('modules.mods.enabled', true);
        config()->set('modules.mods.curseforge_enabled', true);
        config()->set('modules.mods.curseforge_api_key', true);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function loaderChoice(): array
    {
        return ['with loader' => [true], 'without loader' => [false]];
    }

    #[DataProvider('loaderChoice')]
    public function testInstallRecordsTheLoaderChoice(bool $installLoader): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->node->forceFill(['wings_type' => Node::WINGS_TYPE_RS])->save();

        $curseForge = \Mockery::mock(CurseForgeService::class);
        $curseForge->expects('getModpackFile')->with(1, 2)->andReturn(['downloadUrl' => 'https://edge.forgecdn.net/files/0/2/pack.zip']);
        $this->app->instance(CurseForgeService::class, $curseForge);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/modpacks/1/versions/2/install", [
                'project_id' => 1,
                'file_id' => 2,
                'install_loader' => $installLoader,
            ])
            ->assertStatus(Response::HTTP_ACCEPTED);

        $row = DownloadQueue::query()->where('server_id', $server->id)->sole();
        $this->assertSame($installLoader, $row->install_loader);

        Queue::assertPushed(InstallModpackJob::class, fn (InstallModpackJob $job) => $job->installLoader === $installLoader);
    }

    #[DataProvider('loaderChoice')]
    public function testRetryReusesTheRecordedLoaderChoice(bool $installLoader): void
    {
        [$user, $server] = $this->generateTestAccount();
        $item = $this->failedModpack($server->id, $installLoader);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/queue/$item->uuid/retry")
            ->assertOk();

        Queue::assertPushed(InstallModpackJob::class, fn (InstallModpackJob $job) => $job->installLoader === $installLoader && $job->wipeServer === false);
    }

    /**
     * A retry that needs the loader step needs the permissions the install
     * needed. Skipping the step instead would leave a pack that cannot boot.
     */
    public function testRetryOfALoaderInstallNeedsTheStartupPermissions(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_CREATE, Permission::ACTION_FILE_UPDATE]);
        $item = $this->failedModpack($server->id, true);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/queue/$item->uuid/retry")
            ->assertStatus(Response::HTTP_FORBIDDEN);

        Queue::assertNothingPushed();
        $this->assertSame(DownloadQueue::STATUS_FAILED, $item->fresh()->status);
    }

    public function testRetryWithoutTheLoaderNeedsOnlyFilePermissions(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_CREATE, Permission::ACTION_FILE_UPDATE]);
        $item = $this->failedModpack($server->id, false);

        $this->actingAs($user)
            ->postJson("/api/client/servers/$server->uuid/mods/queue/$item->uuid/retry")
            ->assertOk();

        Queue::assertPushed(InstallModpackJob::class, fn (InstallModpackJob $job) => $job->installLoader === false);
    }

    private function failedModpack(int $serverId, bool $installLoader): DownloadQueue
    {
        return DownloadQueue::create([
            'uuid' => Str::uuid()->toString(),
            'server_id' => $serverId,
            'provider' => 'curseforge',
            'source' => 'modpack',
            'project_id' => '1',
            'file_id' => '2',
            'install_loader' => $installLoader,
            'phase' => 'loader',
            'status' => DownloadQueue::STATUS_FAILED,
        ]);
    }
}
