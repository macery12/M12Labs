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
        if (!Schema::hasTable('allocations')) {
            return;
        }

        if (Schema::hasColumn('allocations', 'assigned_to')) {
            DB::statement('ALTER TABLE allocations MODIFY `assigned_to` INT UNSIGNED NULL');
        } elseif (Schema::hasColumn('allocations', 'server_id')) {
            DB::statement('ALTER TABLE allocations MODIFY `server_id` INT UNSIGNED NULL');
        }

        if (Schema::hasColumn('allocations', 'node')) {
            DB::statement('ALTER TABLE allocations MODIFY `node` INT UNSIGNED NOT NULL');
        } elseif (Schema::hasColumn('allocations', 'node_id')) {
            DB::statement('ALTER TABLE allocations MODIFY `node_id` INT UNSIGNED NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('allocations')) {
            return;
        }

        if (Schema::hasColumn('allocations', 'assigned_to')) {
            DB::statement('ALTER TABLE allocations MODIFY `assigned_to` INT NULL');
        } elseif (Schema::hasColumn('allocations', 'server_id')) {
            DB::statement('ALTER TABLE allocations MODIFY `server_id` INT NULL');
        }

        if (Schema::hasColumn('allocations', 'node')) {
            DB::statement('ALTER TABLE allocations MODIFY `node` INT NOT NULL');
        } elseif (Schema::hasColumn('allocations', 'node_id')) {
            DB::statement('ALTER TABLE allocations MODIFY `node_id` INT NOT NULL');
        }
    }
};
