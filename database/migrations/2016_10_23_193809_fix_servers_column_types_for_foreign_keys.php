<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE servers MODIFY node INT UNSIGNED');
        DB::statement('ALTER TABLE servers MODIFY owner INT UNSIGNED');
        DB::statement('ALTER TABLE servers MODIFY allocation INT UNSIGNED');
        DB::statement('ALTER TABLE servers MODIFY service INT UNSIGNED');
        DB::statement('ALTER TABLE servers MODIFY `option` INT UNSIGNED');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE servers MODIFY node INT');
        DB::statement('ALTER TABLE servers MODIFY owner INT');
        DB::statement('ALTER TABLE servers MODIFY allocation INT');
        DB::statement('ALTER TABLE servers MODIFY service INT');
        DB::statement('ALTER TABLE servers MODIFY `option` INT');
    }
};
