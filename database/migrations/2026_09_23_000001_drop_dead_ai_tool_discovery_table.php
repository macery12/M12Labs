<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/*
 * `ai_tool_discovery` was left behind when the AI module stopped using it
 * (fad245f00): the baseline stopped creating it, but installs that already had
 * it kept it, and 2026_09_19_000001 did not rename it with the other AI tables
 * because nothing read it any more.
 *
 * It still carried a foreign key to `ai_conversations`, and a rename carries
 * foreign keys with it -- so the AI extension's `ext_ai_conversations` ended
 * up referenced by a core table. The extension cannot drop that table (it is
 * outside its `ext_ai_` namespace, which the install scanner rightly refuses),
 * so uninstalling the extension with "drop data" failed half way: every other
 * AI table was already gone when the conversations table refused to go.
 *
 * Core created it; core removes it. No down(): nothing reads it, and putting
 * it back would put the foreign key back too.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('ai_tool_discovery');
    }

    public function down(): void
    {
    }
};
