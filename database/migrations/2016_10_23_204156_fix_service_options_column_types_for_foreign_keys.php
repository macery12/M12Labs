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
        if (!Schema::hasTable('service_options')) {
            return;
        }

        if (Schema::hasColumn('service_options', 'parent_service')) {
            DB::statement('ALTER TABLE service_options MODIFY `parent_service` INT UNSIGNED NOT NULL');
        } elseif (Schema::hasColumn('service_options', 'service_id')) {
            DB::statement('ALTER TABLE service_options MODIFY `service_id` INT UNSIGNED NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('service_options')) {
            return;
        }

        if (Schema::hasColumn('service_options', 'parent_service')) {
            DB::statement('ALTER TABLE service_options MODIFY `parent_service` INT NOT NULL');
        } elseif (Schema::hasColumn('service_options', 'service_id')) {
            DB::statement('ALTER TABLE service_options MODIFY `service_id` INT NOT NULL');
        }
    }
};
