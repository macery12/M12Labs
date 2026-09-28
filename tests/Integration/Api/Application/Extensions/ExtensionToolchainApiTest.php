<?php

namespace Everest\Tests\Integration\Api\Application\Extensions;

use Everest\Services\Extensions\ExtensionPanelRebuildService;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Every install rebuilds the interface on the panel host. The Extensions page
 * reads this to say, before the first install fails, that the host has no
 * pnpm or too old a Node.
 */
class ExtensionToolchainApiTest extends ApplicationApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.extensions.enabled', true);
    }

    public function testTheToolchainStatusIsReportedAsIs(): void
    {
        $status = [
            'ready' => false,
            'node' => ['found' => true, 'version' => '18.19.0', 'required' => '>=20.19.0', 'ok' => false],
            'pnpm' => ['found' => false, 'version' => null, 'required' => '10.x', 'ok' => false],
            'disk' => ['freeBytes' => 1024, 'requiredBytes' => 2048, 'ok' => false],
        ];

        $rebuild = \Mockery::mock(ExtensionPanelRebuildService::class);
        $rebuild->shouldReceive('toolchainStatus')->once()->andReturn($status);
        $this->app->instance(ExtensionPanelRebuildService::class, $rebuild);

        $this->getJson('/api/application/extensions/toolchain')
            ->assertOk()
            ->assertJsonPath('object', 'extension_toolchain')
            ->assertJsonPath('attributes', $status);
    }
}
