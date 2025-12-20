<?php

namespace Everest\Tests\Integration\Api\Application\Servers;

use Everest\Models\Node;
use Illuminate\Http\Response;
use Everest\Models\Allocation;
use Everest\Services\Nodes\NodeJWTService;
use Everest\Tests\Traits\CreatesTestJwtToken;
use Everest\Repositories\Wings\DaemonTransferRepository;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class ServerTransferControllerTest extends ApplicationApiIntegrationTestCase
{
    use CreatesTestJwtToken;

    /**
     * Test that a server can be transferred to a new node.
     */
    public function testServerCanBeTransferred()
    {
        $server = $this->createServerModel();
        $targetNode = Node::factory()->create();
        $targetAllocation = Allocation::factory()->create([
            'node_id' => $targetNode->id,
            'server_id' => null,
        ]);

        // Mock the JWT service
        $this->instance(NodeJWTService::class, $mock = \Mockery::mock(NodeJWTService::class));
        $mock->expects('setExpiresAt->setSubject->setClaims->handle')
            ->once()
            ->andReturn($this->createTestToken());

        // Mock the daemon repository
        $this->instance(DaemonTransferRepository::class, $daemonMock = \Mockery::mock(DaemonTransferRepository::class));
        $daemonMock->expects('setServer->notify')->once()->andReturnSelf();

        $response = $this->postJson('/api/application/servers/' . $server->id . '/transfer', [
            'node_id' => $targetNode->id,
            'allocation_id' => $targetAllocation->id,
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'message',
            'transfer',
        ]);

        $this->assertDatabaseHas('server_transfers', [
            'server_id' => $server->id,
            'old_node' => $server->node_id,
            'new_node' => $targetNode->id,
            'old_allocation' => $server->allocation_id,
            'new_allocation' => $targetAllocation->id,
        ]);
    }

    /**
     * Test that validation errors are returned for invalid input.
     */
    public function testValidationErrorsAreReturned()
    {
        $server = $this->createServerModel();

        $response = $this->postJson('/api/application/servers/' . $server->id . '/transfer', [
            'node_id' => 'invalid',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('errors.0.meta.source_field', 'node_id');
        $response->assertJsonPath('errors.0.meta.rule', 'integer');
        $response->assertJsonPath('errors.1.meta.source_field', 'allocation_id');
        $response->assertJsonPath('errors.1.meta.rule', 'required');
    }

    /**
     * Test that an error is returned when transferring to the same node.
     */
    public function testErrorWhenTransferringToSameNode()
    {
        $server = $this->createServerModel();
        $allocation = Allocation::factory()->create([
            'node_id' => $server->node_id,
            'server_id' => null,
        ]);

        $response = $this->postJson('/api/application/servers/' . $server->id . '/transfer', [
            'node_id' => $server->node_id,
            'allocation_id' => $allocation->id,
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }
}
