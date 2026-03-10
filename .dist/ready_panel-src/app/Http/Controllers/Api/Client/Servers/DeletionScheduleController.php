<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DeletionScheduleController extends ClientApiController
{
    /**
     * Schedule server deletion for the renewal date.
     */
    public function schedule(Request $request, Server $server): Response
    {
        $this->assertOwner($request->user()->id, $server->owner_id);

        $server->update([
            'deletion_scheduled_at' => now(),
            'deletion_scheduled_by' => $request->user()->id,
            'deletion_canceled_at' => null,
        ]);

        return $this->returnNoContent();
    }

    /**
     * Cancel a previously scheduled deletion.
     */
    public function cancel(Request $request, Server $server): Response
    {
        $this->assertOwner($request->user()->id, $server->owner_id);

        $server->update([
            'deletion_canceled_at' => now(),
        ]);

        return $this->returnNoContent();
    }

    private function assertOwner(int $userId, int $ownerId): void
    {
        if ($userId !== $ownerId) {
            throw new AccessDeniedHttpException('Only the server owner can manage deletion scheduling.');
        }
    }
}
