<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE allocations MODIFY assigned_to INT UNSIGNED NULL');
        DB::statement('ALTER TABLE allocations MODIFY node INT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE allocations MODIFY assigned_to INT NULL');
        DB::statement('ALTER TABLE allocations MODIFY node INT NOT NULL');
    }
};
