<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean up any out-of-range strings that might have been injected.
        DB::table('users')
            ->whereNotIn('state', ['active', 'suspended'])
            ->update(['state' => 'active']);

        // Re-apply ENUM enforcement (idempotent if already applied).
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `state` ENUM('active','suspended') NOT NULL DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to a varchar allowing nulls.
        DB::statement('ALTER TABLE `users` MODIFY COLUMN `state` VARCHAR(191) NULL DEFAULT NULL');
    }
};
