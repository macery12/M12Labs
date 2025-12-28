<?php

namespace Everest\Services\Servers;

use Everest\Models\Server;
use Illuminate\Support\Arr;
use Illuminate\Database\ConnectionInterface;
use Everest\Traits\Services\ReturnsUpdatedModels;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;

class DetailsModificationService
{
    use ReturnsUpdatedModels;

    /**
     * DetailsModificationService constructor.
     */
    public function __construct(private ConnectionInterface $connection, private DaemonServerRepository $serverRepository)
    {
    }

    /**
     * Update the details for a single server instance.
     *
     * @throws \Throwable
     */
    public function handle(Server $server, array $data): Server
    {
        return $this->connection->transaction(function () use ($data, $server) {
            $owner = $server->owner_id;

            $server->forceFill([
                'external_id' => Arr::get($data, 'external_id'),
                'owner_id' => Arr::get($data, 'owner_id'),
                'name' => Arr::get($data, 'name'),
                'description' => Arr::get($data, 'description') ?? '',
                'renewal_date' => array_key_exists('renewal_date', $data) ? Arr::get($data, 'renewal_date') : $server->renewal_date,
                'billing_product_id' => array_key_exists('billing_product_id', $data) ? Arr::get($data, 'billing_product_id') : $server->billing_product_id,
                'allow_plan_changes' => array_key_exists('allow_plan_changes', $data) ? Arr::get($data, 'allow_plan_changes') : $server->allow_plan_changes,
            ])->saveOrFail();

            // If the owner_id value is changed we need to revoke any tokens that exist for the server
            // on the Wings instance so that the old owner no longer has any permission to access the
            // websockets.
            if ($server->owner_id !== $owner) {
                try {
                    $this->serverRepository->setServer($server)->revokeUserJTI($owner);
                } catch (DaemonConnectionException $exception) {
                    // Do nothing. A failure here is not ideal, but it is likely to be caused by Wings
                    // being offline, or in an entirely broken state. Remember, these tokens reset every
                    // few minutes by default, we're just trying to help it along a little quicker.
                }
            }

            return $server;
        });
    }
}
