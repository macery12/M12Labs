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
        // On existing installations this column may already have been renamed
        // to service_id by a later migration.
        if (!Schema::hasColumn('service_options', 'parent_service')) {
            return;
        }

        DB::statement('ALTER TABLE service_options MODIFY parent_service INT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('service_options', 'parent_service')) {
            return;
        }

        DB::statement('ALTER TABLE service_options MODIFY parent_service INT NOT NULL');
    }
};
