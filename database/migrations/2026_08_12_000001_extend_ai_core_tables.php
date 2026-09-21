<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Link a ticket to a server.
 *
 * The filename is a leftover and is kept deliberately. This migration once
 * also reshaped the AI tables -- `ai_conversations`, `ai_messages` and
 * `ai_usage_logs` -- into their agent-ready form, in the same file because
 * they were core tables then. They are an extension's now, created by its own
 * migration in the shape this one used to patch them into, so all of that is
 * gone.
 *
 * What is left is the part that was never about AI: `tickets.server_id`, a
 * core relation `Ticket::server()` reads. Renaming the file to say so would
 * mean an existing install, which has this name recorded as applied, running
 * the new name too and trying to add the column a second time.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->nullable()->after('user_id');
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['server_id']);
            $table->dropColumn('server_id');
        });
    }
};
