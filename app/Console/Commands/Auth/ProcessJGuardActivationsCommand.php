<?php

namespace Everest\Console\Commands\Auth;

use Everest\Models\User;
use Illuminate\Console\Command;
use Everest\Models\JGuardEntry;

class ProcessJGuardActivationsCommand extends Command
{
    protected $description = 'Activate delayed jGuard accounts whose delay period has elapsed.';

    protected $signature = 'p:auth:jguard:process-activations';

    /**
     * Handle command execution.
     */
    public function handle(): void
    {
        $entries = JGuardEntry::where('status', JGuardEntry::STATUS_PENDING)
            ->where('approval_mode', JGuardEntry::MODE_DELAYED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('user')
            ->get();

        foreach ($entries as $entry) {
            $user = $entry->user;
            if (!$user) {
                $entry->update(['status' => JGuardEntry::STATUS_APPROVED]);
                continue;
            }

            $entry->update(['status' => JGuardEntry::STATUS_APPROVED]);
            $user->update(['state' => null]);

            $this->line("Activated user #{$user->id} ({$user->username}) after jGuard delay.");
        }

        if ($entries->count() > 0) {
            $this->info("Processed {$entries->count()} jGuard delayed activation(s).");
        }
    }
}
