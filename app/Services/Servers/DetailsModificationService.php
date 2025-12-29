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

            // DEBUG: Log what we're starting with
            Log::info('DetailsModificationService - Starting', [
                'server_id' => $server->id,
                'server_allow_plan_changes_before' => $server->allow_plan_changes,
                'data_has_allow_plan_changes' => array_key_exists('allow_plan_changes', $data),
                'data_allow_plan_changes_value' => $data['allow_plan_changes'] ?? 'NOT_SET',
            ]);

            // Only update fields that are actually present in the request data
            // to avoid overwriting existing values with null
            $fillData = [];
            
            if (array_key_exists('external_id', $data)) {
                $fillData['external_id'] = $data['external_id'];
            }
            if (array_key_exists('owner_id', $data)) {
                $fillData['owner_id'] = $data['owner_id'];
            }
            if (array_key_exists('name', $data)) {
                $fillData['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $fillData['description'] = $data['description'] ?? '';
            }
            if (array_key_exists('renewal_date', $data)) {
                $fillData['renewal_date'] = $data['renewal_date'];
            }
            if (array_key_exists('billing_product_id', $data)) {
                $fillData['billing_product_id'] = $data['billing_product_id'];
            }
            if (array_key_exists('allow_plan_changes', $data)) {
                $fillData['allow_plan_changes'] = $data['allow_plan_changes'];
            }

            // DEBUG: Log what we're about to fill
            Log::info('DetailsModificationService - About to forceFill', [
                'server_id' => $server->id,
                'fillData' => $fillData,
                'fillData_has_allow_plan_changes' => array_key_exists('allow_plan_changes', $fillData),
            ]);

            $server->forceFill($fillData)->saveOrFail();

            // Refresh the model to ensure all casted attributes are properly loaded
            $server->refresh();
            
            // DEBUG: Log final result
            Log::info('DetailsModificationService - After save and refresh', [
                'server_id' => $server->id,
                'allow_plan_changes_value' => $server->allow_plan_changes,
            ]);

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
