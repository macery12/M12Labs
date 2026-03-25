<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE server_variables MODIFY server_id INT UNSIGNED NULL');
        DB::statement('ALTER TABLE server_variables MODIFY variable_id INT UNSIGNED NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE server_variables MODIFY server_id INT NULL');
        DB::statement('ALTER TABLE server_variables MODIFY variable_id INT NOT NULL');
    }
};
