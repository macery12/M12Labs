<?php

namespace Everest\Tests\Integration\Api\Client\Server;

use Everest\Models\User;
use Everest\Models\Server;
use Illuminate\Support\Facades\Cache;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

/**
 * The dashboard polled /servers/{id}/resources once per server every ten
 * seconds. This answers a page of servers in one request, with one Wings call
 * per node, under the single-server endpoint's visibility and state rules.
 */
class ResourceSummaryControllerTest extends ClientApiIntegrationTestCase
{
    private \Mockery\MockInterface $repository;

    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->repository = \Mockery::mock(DaemonServerRepository::class);
        $this->repository->shouldReceive('setNode')->andReturnSelf();
        $this->app->instance(DaemonServerRepository::class, $this->repository);
    }

    /**
     * @param list<Server> $servers
     *
     * @return list<array<string, mixed>>
     */
    private function wingsListing(array $servers): array
    {
        return array_map(fn (Server $server) => [
            'state' => 'running',
            'is_suspended' => false,
            'utilization' => ['memory_bytes' => 1024, 'cpu_absolute' => 12.5, 'disk_bytes' => 2048, 'network' => ['rx_bytes' => 1, 'tx_bytes' => 2], 'uptime' => 99],
            'configuration' => ['uuid' => $server->uuid, 'environment' => ['SECRET' => 'never-leaves-the-node']],
        ], $servers);
    }

    private function fetch(User $user, array $servers): \Illuminate\Testing\TestResponse
    {
        $ids = implode(',', array_map(fn (Server $server) => $server->uuidShort, $servers));

        return $this->actingAs($user)->getJson('/api/client/servers/resources?ids=' . $ids);
    }

    public function testAPageOfServersOnOneNodeCostsOneWingsCall(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $first = $this->createServerModel(['user_id' => $user->id]);
        $second = $this->createServerModel(['user_id' => $user->id, 'node_id' => $first->node_id]);

        $this->repository->shouldReceive('getAll')->once()->andReturn($this->wingsListing([$first, $second]));

        $this->fetch($user, [$first, $second])
            ->assertOk()
            ->assertJsonPath("data.{$first->uuidShort}.current_state", 'running')
            ->assertJsonPath("data.{$second->uuidShort}.resources.memory_bytes", 1024)
            ->assertJsonMissingPath("data.{$first->uuidShort}.configuration");
    }

    /** A server the caller could not open alone is simply absent, not an error. */
    public function testAnotherUsersServerIsLeftOut(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $mine = $this->createServerModel(['user_id' => $user->id]);
        $theirs = $this->createServerModel();

        $this->repository->shouldReceive('getAll')->andReturn($this->wingsListing([$mine, $theirs]));

        $response = $this->fetch($user, [$mine, $theirs])->assertOk();

        $this->assertArrayHasKey($mine->uuidShort, $response->json('data'));
        $this->assertArrayNotHasKey($theirs->uuidShort, $response->json('data'));
    }

    public function testASubuserSeesTheServerTheyWereAddedTo(): void
    {
        [$user, $server] = $this->generateTestAccount(['websocket.connect']);

        $this->repository->shouldReceive('getAll')->once()->andReturn($this->wingsListing([$server]));

        $this->fetch($user, [$server])->assertOk()->assertJsonPath("data.{$server->uuidShort}.current_state", 'running');
    }

    /** The single endpoint answers these with a conflict; here they are null, and Wings is not asked. */
    public function testASuspendedServerIsNullWithoutAskingWings(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $server = $this->createServerModel(['user_id' => $user->id, 'status' => Server::STATUS_SUSPENDED]);

        $this->repository->shouldNotReceive('getAll');

        $this->fetch($user, [$server])->assertOk()->assertJsonPath("data.{$server->uuidShort}", null);
    }

    public function testAnUnreachableNodeNullsOnlyItsOwnServers(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $server = $this->createServerModel(['user_id' => $user->id]);

        $this->repository->shouldReceive('getAll')->andThrow(new DaemonConnectionException(new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', '/'))));

        $this->fetch($user, [$server])->assertOk()->assertJsonPath("data.{$server->uuidShort}", null);
    }

    public function testTheIdsAreRequired(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/client/servers/resources')->assertStatus(422);
    }
}
