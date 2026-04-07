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
        if (!Schema::hasTable('service_variables') || !Schema::hasColumn('service_variables', 'option_id')) {
            return;
        }

        DB::statement('ALTER TABLE service_variables MODIFY `option_id` INT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('service_variables') || !Schema::hasColumn('service_variables', 'option_id')) {
            return;
        }

        DB::statement('ALTER TABLE service_variables MODIFY `option_id` INT NOT NULL');
    }
};
