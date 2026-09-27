<?php

namespace Everest\Tests\Integration\Api\Application\Plugins;

use Illuminate\Support\Str;
use Everest\Models\DownloadQueue;
use Everest\Models\MarketplaceInstallLog;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class PluginFailuresTest extends ApplicationApiIntegrationTestCase
{
    public function testFailuresListTheReasonFromTheDownloadQueue(): void
    {
        $server = $this->createServerModel();

        DownloadQueue::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'provider' => 'modrinth',
            'source' => 'modrinth',
            'project_id' => 'abc123',
            'file_id' => 'f1',
            'file_name' => 'coolmod.jar',
            'error_message' => 'Checksum did not match.',
            'status' => DownloadQueue::STATUS_FAILED,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        MarketplaceInstallLog::query()->create([
            'provider' => 'modrinth',
            'type' => 'mod',
            'project_id' => 'abc123',
            'status' => MarketplaceInstallLog::STATUS_FAILED,
            'server_id' => $server->id,
        ]);
        MarketplaceInstallLog::query()->create([
            'provider' => 'modrinth',
            'type' => 'mod',
            'project_id' => 'ok',
            'status' => MarketplaceInstallLog::STATUS_SUCCESS,
            'server_id' => $server->id,
        ]);

        $response = $this->getJson('/api/application/plugins/failures')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_id', 'abc123')
            ->assertJsonPath('data.0.file_name', 'coolmod.jar')
            ->assertJsonPath('data.0.error', 'Checksum did not match.')
            ->assertJsonPath('data.0.server.name', $server->name);
    }
}
