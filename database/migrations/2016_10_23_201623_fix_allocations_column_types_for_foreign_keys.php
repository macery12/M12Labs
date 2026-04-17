<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // On existing installations these columns may already have been renamed
        // (assigned_to → server_id, node → node_id) by a later migration.
        if (Schema::hasColumn('allocations', 'assigned_to')) {
            DB::statement('ALTER TABLE allocations MODIFY assigned_to INT UNSIGNED NULL');
        }

        if (Schema::hasColumn('allocations', 'node')) {
            DB::statement('ALTER TABLE allocations MODIFY node INT UNSIGNED NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('allocations', 'assigned_to')) {
            DB::statement('ALTER TABLE allocations MODIFY assigned_to INT NULL');
        }

        if (Schema::hasColumn('allocations', 'node')) {
            DB::statement('ALTER TABLE allocations MODIFY node INT NOT NULL');
        }
    }
};
