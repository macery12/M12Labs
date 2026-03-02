<?php

namespace Everest\Console\Commands\Billing;

use Everest\Models\Server;
use Illuminate\Console\Command;
use Everest\Services\Servers\ServerDeletionService;

class DeleteScheduledServersCommand extends Command
{
    protected $description = 'Delete servers that are scheduled for deletion on their renewal date.';

    protected $signature = 'p:billing:delete-scheduled-servers';

    public function __construct(private ServerDeletionService $deletionService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $today = now()->startOfDay();

        $servers = Server::whereNotNull('renewal_date')
            ->whereDate('renewal_date', '<=', $today)
            ->whereNotNull('deletion_scheduled_at')
            ->get();

        foreach ($servers as $server) {
            if (!$server->isDeletionScheduled()) {
                continue;
            }

            $this->info("Deleting server {$server->id} scheduled for {$server->renewal_date}");

            try {
                $this->deletionService->handle($server);
            } catch (\Throwable $exception) {
                $this->error("Failed deleting server {$server->id}: {$exception->getMessage()}");
                report($exception);
            }
        }
    }
}
