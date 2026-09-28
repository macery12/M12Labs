<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Carbon\Carbon;
use Everest\Models\Node;
use Everest\Models\Server;
use Everest\Models\Subuser;
use Illuminate\Support\Arr;
use Illuminate\Cache\Repository;
use Everest\Services\Access\DelegatedSession;
use Everest\Transformers\Api\Client\StatsTransformer;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Exceptions\Http\Server\ServerStateConflictException;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;
use Everest\Http\Requests\Api\Client\Servers\GetServerResourcesRequest;

/**
 * Live usage for a page of servers in one request.
 *
 * The dashboard used to poll /servers/{id}/resources once per server every ten
 * seconds: twenty servers meant two requests a second from one open tab, each
 * paying the full request cost and its own Wings round-trip. This answers the
 * whole page at once and asks each node for all its servers in a single call.
 *
 * Visibility and state rules are the single-server endpoint's: a server the
 * caller could not open is left out, and one the single endpoint answers with
 * a state conflict (suspended, installing, transferring, restoring, node in
 * maintenance) comes back as null -- as does one whose node is unreachable, so
 * one bad node never fails the rest of the page.
 */
class ResourceSummaryController extends ClientApiController
{
    /** Same freshness as the single-server endpoint's cache. */
    private const CACHE_SECONDS = 20;

    public function __construct(
        private Repository $cache,
        private DaemonServerRepository $repository,
        private DelegatedSession $delegated,
    ) {
        parent::__construct();
    }

    public function __invoke(GetServerResourcesRequest $request): array
    {
        $user = $request->user();

        $servers = Server::query()
            ->whereIn('uuidShort', $request->identifiers())
            ->with(['node', 'transfer'])
            ->get();

        $subuserOf = Subuser::query()
            ->where('user_id', $user->id)
            ->whereIn('server_id', $servers->modelKeys())
            ->pluck('server_id')
            ->flip();

        $result = [];
        $pending = [];

        foreach ($servers as $server) {
            $visible = $user->id === $server->owner_id
                || $user->isOwner()
                || isset($subuserOf[$server->id])
                || $this->delegated->covers($user, $server);

            if (!$visible) {
                continue;
            }

            try {
                $server->validateCurrentState();
            } catch (ServerStateConflictException) {
                $result[$server->uuidShort] = null;

                continue;
            }

            $cached = $this->cache->get("resources:$server->uuid");
            if (is_array($cached)) {
                $result[$server->uuidShort] = $this->transform($cached);

                continue;
            }

            $pending[$server->node_id][] = $server;
        }

        foreach ($pending as $nodeServers) {
            $details = $this->nodeDetails($nodeServers[0]->node);

            foreach ($nodeServers as $server) {
                $entry = $details[$server->uuid] ?? null;

                if ($entry !== null) {
                    // Warm the single-server cache the server pages read.
                    $this->cache->put("resources:$server->uuid", $entry, Carbon::now()->addSeconds(self::CACHE_SECONDS));
                }

                $result[$server->uuidShort] = $entry === null ? null : $this->transform($entry);
            }
        }

        return [
            'object' => 'server_resources',
            'data' => (object) $result,
        ];
    }

    /**
     * Every server the node reports, keyed by uuid, in one Wings call.
     * `configuration` (environment, mounts) is dropped before anything is
     * cached or used -- only usage leaves this method.
     *
     * @return array<string, array<string, mixed>>
     */
    private function nodeDetails(Node $node): array
    {
        try {
            $all = $this->repository->setNode($node)->getAll();
        } catch (DaemonConnectionException) {
            return [];
        }

        $byUuid = [];
        foreach ($all as $details) {
            $uuid = Arr::get($details, 'configuration.uuid');
            if (is_string($uuid)) {
                $byUuid[$uuid] = Arr::except($details, 'configuration');
            }
        }

        return $byUuid;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function transform(array $details): array
    {
        return (new StatsTransformer())->transform($details);
    }
}
