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
        if (!Schema::hasTable('nodes')) {
            return;
        }

        if (Schema::hasColumn('nodes', 'location')) {
            DB::statement('ALTER TABLE nodes MODIFY `location` INT UNSIGNED NOT NULL');
        } elseif (Schema::hasColumn('nodes', 'location_id')) {
            DB::statement('ALTER TABLE nodes MODIFY `location_id` INT UNSIGNED NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('nodes')) {
            return;
        }

        if (Schema::hasColumn('nodes', 'location')) {
            DB::statement('ALTER TABLE nodes MODIFY `location` INT NOT NULL');
        } elseif (Schema::hasColumn('nodes', 'location_id')) {
            DB::statement('ALTER TABLE nodes MODIFY `location_id` INT NOT NULL');
        }
    }
};
