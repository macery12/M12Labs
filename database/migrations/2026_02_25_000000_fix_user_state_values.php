<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize existing rows first.
        DB::table('users')
            ->whereNull('state')
            ->orWhere('state', '=','')
            ->update(['state' => 'active']);

        // Ensure the column is non-null with a default at the database level.
        // Use a raw statement to avoid requiring doctrine/dbal in migrations.
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `state` VARCHAR(191) NOT NULL DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is intentionally irreversible.
    }
};
