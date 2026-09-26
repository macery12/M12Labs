<?php

namespace Everest\Services\Servers;

use Everest\Models\Node;
use Illuminate\Contracts\Cache\Repository as Cache;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Live power state (running / offline…) for every server on a node, for the
 * admin server list and server page.
 *
 * Those pages used to show the lifecycle state alone, so any server that
 * wasn't suspended read "Active" even while it was offline. Asking Wings once
 * per node keeps the cost proportional to nodes rather than servers, and the
 * short cache absorbs the list's polling and several admins looking at once.
 *
 * An unreachable node is cached too: every miss would otherwise wait out the
 * connect timeout again.
 */
class ServerPowerStateService
{
    public const TTL = 20;

    public const STATES = ['running', 'starting', 'stopping', 'offline'];

    public function __construct(private Cache $cache, private DaemonServerRepository $repository)
    {
    }

    /**
     * @return array{reachable: bool, states: array<string, string>}
     */
    public function forNode(Node $node): array
    {
        return $this->cache->remember(self::key($node), self::TTL, fn () => $this->fetch($node));
    }

    public static function key(Node $node): string
    {
        return "server_power_states:node:{$node->id}";
    }

    /**
     * @return array{reachable: bool, states: array<string, string>}
     */
    private function fetch(Node $node): array
    {
        try {
            $servers = $this->repository->setNode($node)->getAll();
        } catch (DaemonConnectionException) {
            return ['reachable' => false, 'states' => []];
        }

        // Only this node's servers as the panel knows them: Wings can still
        // hold a server the panel has deleted.
        $known = array_flip($node->servers()->pluck('uuid')->all());

        $states = [];
        foreach ($servers as $server) {
            $uuid = $server['configuration']['uuid'] ?? $server['uuid'] ?? null;
            $state = $server['state'] ?? null;
            if (is_string($uuid) && isset($known[$uuid]) && in_array($state, self::STATES, true)) {
                $states[$uuid] = $state;
            }
        }

        return ['reachable' => true, 'states' => $states];
    }
}
