<?php

namespace Everest\Console\Commands\AI;

use Everest\Models\AiConversation;
use Illuminate\Console\Command;

class PruneAiConversationsCommand extends Command
{
    protected $signature = 'p:ai:prune-conversations';

    protected $description = 'Delete unsaved AI conversations whose expiry date has passed.';

    public function handle(): int
    {
        $deleted = AiConversation::where('is_saved', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Pruned {$deleted} expired AI conversation(s).");

        return Command::SUCCESS;
    }
}
