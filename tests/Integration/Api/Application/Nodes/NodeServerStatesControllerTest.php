<?php

namespace Everest\Tests\Integration\Api\Application\Nodes;

use GuzzleHttp\Psr7\Request;
use Everest\Models\AdminRole;
use GuzzleHttp\Exception\ConnectException;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * The admin server list showed "Active" for every server that wasn't
 * suspended, even offline ones. It now asks each node once for the power
 * state of all its servers.
 */
class NodeServerStatesControllerTest extends ApplicationApiIntegrationTestCase
{
    public function testReturnsEachServersStateFromOneCallPerNode(): void
    {
        $running = $this->createServerModel();
        $offline = $this->createServerModel(['node_id' => $running->node_id]);

        $this->instance(DaemonServerRepository::class, $daemon = \Mockery::mock(DaemonServerRepository::class));
        $daemon->expects('setNode->getAll')->once()->andReturn([
            ['state' => 'running', 'is_suspended' => false, 'configuration' => ['uuid' => $running->uuid]],
            ['state' => 'offline', 'is_suspended' => false, 'configuration' => ['uuid' => $offline->uuid]],
            // Still on Wings after the panel deleted it: not ours to report.
            ['state' => 'running', 'configuration' => ['uuid' => 'd0d0d0d0-0000-4000-8000-000000000000']],
        ]);

        $url = "/api/application/nodes/{$running->node_id}/server-states";
        $this->getJson($url)
            ->assertOk()
            ->assertExactJson([
                'reachable' => true,
                'states' => [$running->uuid => 'running', $offline->uuid => 'offline'],
            ]);

        // Cached: the list polls, and the mock allows one daemon call.
        $this->getJson($url)->assertOk()->assertJsonPath("states.{$running->uuid}", 'running');
    }

    public function testAnUnreachableNodeReportsUnknownInsteadOfFailing(): void
    {
        $server = $this->createServerModel();

        $this->instance(DaemonServerRepository::class, $daemon = \Mockery::mock(DaemonServerRepository::class));
        $daemon->expects('setNode->getAll')->once()->andThrow(
            new DaemonConnectionException(new ConnectException('Connection refused', new Request('GET', '/api/servers')), false)
        );

        $url = "/api/application/nodes/{$server->node_id}/server-states";
        $this->getJson($url)
            ->assertOk()
            ->assertExactJson(['reachable' => false, 'states' => []]);

        // The failure is cached too, so the next poll doesn't wait out the
        // connect timeout again.
        $this->getJson($url)->assertOk()->assertJsonPath('reachable', false);
    }

    public function testAKeyWithoutServerReadIsRefused(): void
    {
        $server = $this->createServerModel();
        $this->createNewScopedApiKey([AdminRole::NODES_READ]);

        $this->assertApiKeyDenied($this->getJson("/api/application/nodes/{$server->node_id}/server-states"));
    }
}
