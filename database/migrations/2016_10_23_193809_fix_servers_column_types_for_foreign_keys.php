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
        if (!Schema::hasTable('servers')) {
            return;
        }

        $columns = [
            ['legacy' => 'node', 'current' => 'node_id'],
            ['legacy' => 'owner', 'current' => 'owner_id'],
            ['legacy' => 'allocation', 'current' => 'allocation_id'],
            ['legacy' => 'service', 'current' => 'service_id'],
            ['legacy' => 'option', 'current' => 'option_id'],
        ];

        foreach ($columns as $column) {
            $target = Schema::hasColumn('servers', $column['legacy'])
                ? $column['legacy']
                : (Schema::hasColumn('servers', $column['current']) ? $column['current'] : null);

            if ($target !== null) {
                DB::statement(sprintf('ALTER TABLE servers MODIFY `%s` INT UNSIGNED', $target));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('servers')) {
            return;
        }

        $columns = [
            ['legacy' => 'node', 'current' => 'node_id'],
            ['legacy' => 'owner', 'current' => 'owner_id'],
            ['legacy' => 'allocation', 'current' => 'allocation_id'],
            ['legacy' => 'service', 'current' => 'service_id'],
            ['legacy' => 'option', 'current' => 'option_id'],
        ];

        foreach ($columns as $column) {
            $target = Schema::hasColumn('servers', $column['legacy'])
                ? $column['legacy']
                : (Schema::hasColumn('servers', $column['current']) ? $column['current'] : null);

            if ($target !== null) {
                DB::statement(sprintf('ALTER TABLE servers MODIFY `%s` INT', $target));
            }
        }
    }
};
