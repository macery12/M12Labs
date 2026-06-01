<?php

namespace Everest\Console\Commands\Billing;

use Everest\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Everest\Repositories\Wings\DaemonConfigurationRepository;

class RefreshNodeAvailabilityCommand extends Command
{
    protected $signature = 'billing:refresh-node-availability';

    protected $description = 'Refresh Wings availability cache for all deployable nodes';

    public function handle(DaemonConfigurationRepository $repo): void
    {
        $nodes = Node::where('deployable', true)->orWhere('deployable_free', true)->get();

        foreach ($nodes as $node) {
            $available = false;
            try {
                $repo->setNode($node)->getSystemInformation();
                $available = true;
            } catch (\Throwable) {
                // Node is unreachable — cache as unavailable
            }

            // TTL of 90 seconds: longer than the 30-second Cache::remember TTL so a
            // background-refreshed value is always fresher than the on-demand fallback.
            Cache::put("billing.node_available.{$node->id}", $available, 90);
        }

        $this->info("Refreshed availability for {$nodes->count()} node(s).");
    }
}
