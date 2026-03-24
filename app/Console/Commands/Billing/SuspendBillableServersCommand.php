<?php

namespace Everest\Console\Commands\Billing;

use Everest\Models\Server;
use Illuminate\Console\Command;
use Everest\Services\Servers\SuspensionService;
use Everest\Services\Servers\ServerDeletionService;

class SuspendBillableServersCommand extends Command
{
    protected $description = 'An automated task to suspend billable servers with past renewal dates.';

    protected $signature = 'p:billing:suspend-billable-servers';

    /**
     * SuspendBillableServersCommand constructor.
     */
    public function __construct(private SuspensionService $suspension, private ServerDeletionService $deletion)
    {
        parent::__construct();
    }

    /**
     * Handle command execution.
     */
    public function handle()
    {
        foreach (Server::whereNotNull('renewal_date')->get() as $server) {
            $daysOverdue = $server->renewalDate->diffInDays(now());
            $threshold = config('modules.billing.renewal.threshold');

            if ($server->renewalDate->isPast()) {
                if (!$server->isSuspended()) {
                    $this->info("suspending server {$server->id}, overdue by {$daysOverdue} days");
                    $this->suspension->toggle($server, 'suspend');
                } elseif ($daysOverdue > $threshold) {
                    $this->info("deleting server {$server->id}, overdue by {$daysOverdue} days");
                    $this->deletion->handle($server)->withForce();
                }
            }
        }
    }
}
