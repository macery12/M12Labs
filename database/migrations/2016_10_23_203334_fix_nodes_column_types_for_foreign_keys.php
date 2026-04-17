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
        // to location_id by a later migration.
        if (!Schema::hasColumn('nodes', 'location')) {
            return;
        }

        DB::statement('ALTER TABLE nodes MODIFY location INT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('nodes', 'location')) {
            return;
        }

        DB::statement('ALTER TABLE nodes MODIFY location INT NOT NULL');
    }
};
