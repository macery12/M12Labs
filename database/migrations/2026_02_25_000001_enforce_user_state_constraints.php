<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert any lingering NULL/empty values to 'active'.
        DB::table('users')
            ->whereNull('state')
            ->orWhere('state', '=','')
            ->update(['state' => 'active']);

        // Convert column to an ENUM type to enforce allowed values at the DB level.
        // Note: this uses raw SQL and assumes MySQL/MariaDB. Adjust if using another DB.
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `state` ENUM('active','suspended') NOT NULL DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to a varchar allowing nulls.
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `state` VARCHAR(191) NULL DEFAULT NULL");
    }
};
