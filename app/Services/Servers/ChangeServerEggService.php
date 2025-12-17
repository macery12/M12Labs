<?php

namespace Everest\Services\Servers;

use Everest\Models\Egg;
use Everest\Models\Server;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Illuminate\Database\ConnectionInterface;
use Everest\Repositories\Wings\DaemonServerRepository;

class ChangeServerEggService
{
    /**
     * ChangeServerEggService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private ReinstallServerService $reinstallService
    ) {
    }

    /**
     * Change a server's egg and trigger a reinstall.
     * 
     * @throws \Throwable
     * @throws DisplayException
     */
    public function handle(Server $server, int $newEggId): Server
    {
        // Validate server state
        if ($server->status !== null) {
            throw new DisplayException('The server must be stopped before changing eggs.');
        }

        // Validate server is installed
        if (!$server->isInstalled()) {
            throw new DisplayException('The server must be installed before changing eggs.');
        }

        // Get the server's product category
        $product = Product::find($server->billing_product_id);
        if (!$product) {
            throw new DisplayException('This server does not have a billing product assigned.');
        }

        $category = $product->category;
        if (!$category) {
            throw new DisplayException('This server\'s product does not have a category assigned.');
        }

        // Check if egg changing is allowed for this category
        if (!$category->allow_egg_changes) {
            throw new DisplayException('Egg changes are not allowed for this server\'s category.');
        }

        // Get allowed eggs for the category
        $allowedEggs = $category->allowed_eggs ?? [$category->egg_id];

        // Validate that the new egg is in the allowed list
        if (!in_array($newEggId, $allowedEggs)) {
            throw new DisplayException('The selected egg is not allowed for this server\'s category.');
        }

        // Validate that the new egg exists
        $newEgg = Egg::find($newEggId);
        if (!$newEgg) {
            throw new DisplayException('The selected egg does not exist.');
        }

        // Ensure the egg is from the same nest
        if ($newEgg->nest_id !== $server->nest_id) {
            throw new DisplayException('The selected egg must be from the same nest as the server.');
        }

        return $this->connection->transaction(function () use ($server, $newEgg) {
            // Update the server's egg
            $server->update([
                'egg_id' => $newEgg->id,
                'startup' => $newEgg->startup,
                'image' => current($newEgg->docker_images),
            ]);

            // Trigger reinstall (which will wipe filesystem automatically)
            $this->reinstallService->handle($server);

            return $server->refresh();
        });
    }
}
