<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/*
 * Move AI-owned data under the extension table namespace before the module is
 * lifted out of core. Schema::rename preserves every row, index and foreign
 * key; the guarded helper also makes a retry safe if a database committed some
 * DDL before an interrupted migration could be recorded.
 */
return new class () extends Migration {
    public function up(): void
    {
        // Keep the Schema::rename calls literal. Besides making the migration
        // readable, the shipped-schema guard can then follow each rename and
        // prove the baseline describes the final table names.
        $this->renameExactlyOne('ai_conversations', 'ext_ai_conversations', fn () => Schema::rename('ai_conversations', 'ext_ai_conversations'));
        $this->renameExactlyOne('ai_messages', 'ext_ai_messages', fn () => Schema::rename('ai_messages', 'ext_ai_messages'));
        $this->renameExactlyOne('ai_tool_calls', 'ext_ai_tool_calls', fn () => Schema::rename('ai_tool_calls', 'ext_ai_tool_calls'));
        $this->renameExactlyOne('ai_pending_actions', 'ext_ai_pending_actions', fn () => Schema::rename('ai_pending_actions', 'ext_ai_pending_actions'));
        $this->renameExactlyOne('ai_turn_events', 'ext_ai_turn_events', fn () => Schema::rename('ai_turn_events', 'ext_ai_turn_events'));
        $this->renameExactlyOne('ai_usage_logs', 'ext_ai_usage_logs', fn () => Schema::rename('ai_usage_logs', 'ext_ai_usage_logs'));
        $this->renameExactlyOne('ai_budget_reservations', 'ext_ai_budget_reservations', fn () => Schema::rename('ai_budget_reservations', 'ext_ai_budget_reservations'));
    }

    public function down(): void
    {
        $this->renameExactlyOne('ext_ai_budget_reservations', 'ai_budget_reservations', fn () => Schema::rename('ext_ai_budget_reservations', 'ai_budget_reservations'));
        $this->renameExactlyOne('ext_ai_usage_logs', 'ai_usage_logs', fn () => Schema::rename('ext_ai_usage_logs', 'ai_usage_logs'));
        $this->renameExactlyOne('ext_ai_turn_events', 'ai_turn_events', fn () => Schema::rename('ext_ai_turn_events', 'ai_turn_events'));
        $this->renameExactlyOne('ext_ai_pending_actions', 'ai_pending_actions', fn () => Schema::rename('ext_ai_pending_actions', 'ai_pending_actions'));
        $this->renameExactlyOne('ext_ai_tool_calls', 'ai_tool_calls', fn () => Schema::rename('ext_ai_tool_calls', 'ai_tool_calls'));
        $this->renameExactlyOne('ext_ai_messages', 'ai_messages', fn () => Schema::rename('ext_ai_messages', 'ai_messages'));
        $this->renameExactlyOne('ext_ai_conversations', 'ai_conversations', fn () => Schema::rename('ext_ai_conversations', 'ai_conversations'));
    }

    private function renameExactlyOne(string $from, string $to, Closure $rename): void
    {
        $hasFrom = Schema::hasTable($from);
        $hasTo = Schema::hasTable($to);

        if ($hasFrom && !$hasTo) {
            $rename();

            return;
        }

        if (!$hasFrom && $hasTo) {
            // A previous attempt committed this rename before a later DDL
            // statement failed. Continue from the first unfinished table.
            return;
        }

        $state = $hasFrom ? 'both tables exist' : 'neither table exists';

        throw new RuntimeException(sprintf('Cannot rename AI table "%s" to "%s": %s.', $from, $to, $state));
    }
};
