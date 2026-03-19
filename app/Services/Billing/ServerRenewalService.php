<?php

namespace Everest\Services\Billing;

use Everest\Models\Server;
use Everest\Services\Servers\SuspensionService;

class ServerRenewalService
{
    public function __construct(private SuspensionService $suspensionService)
    {
        //
    }

    /**
     * Process the renewal of an existing billable server.
     */
    public function handle(Server $server): Server
    {
        if ($server->isSuspended()) {
            $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        }

        $days = config('modules.billing.renewal.days', 30);
        $new_date = $server->renewal_date->addDays($days)->toDateTimeString();
        
        $server->update(['renewal_date' => $new_date]);

        return $server;
    }
}
