<?php

namespace Everest\Console\Commands\Queue;

use Illuminate\Console\Command;
use Everest\Services\Queue\HorizonProvisioningReconciler;

class ReconcileHorizonCommand extends Command
{
    protected $signature = 'p:queue:reconcile {--now : Restart immediately instead of waiting for a second observation}';

    protected $description = 'Restart Horizon if it is not running a supervisor its provisioning plan requires';

    public function handle(HorizonProvisioningReconciler $reconciler): int
    {
        $restarted = $this->option('now') ? $reconciler->reconcileNow() : $reconciler->reconcile();

        if ($restarted !== null) {
            $this->warn(sprintf('Horizon was missing %s; asked it to restart.', $restarted));
        }

        return self::SUCCESS;
    }
}
