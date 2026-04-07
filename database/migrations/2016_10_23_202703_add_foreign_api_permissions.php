<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddForeignApiPermissions extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('api_permissions') || !Schema::hasColumn('api_permissions', 'key_id')) {
            return;
        }

        if ($this->hasForeignKeyOnColumn('api_permissions', 'key_id')) {
            return;
        }

        $this->ensureIndex('api_permissions', 'key_id');

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (`id`)',
            $this->wrap('api_permissions'),
            $this->wrap($this->foreignName('api_permissions', 'key_id')),
            $this->wrap('key_id'),
            $this->wrap('api_keys')
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('api_permissions') || !Schema::hasColumn('api_permissions', 'key_id')) {
            return;
        }

        foreach ($this->foreignKeysForColumn('api_permissions', 'key_id') as $foreignKey) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY %s',
                $this->wrap('api_permissions'),
                $this->wrap($foreignKey)
            ));
        }

        $indexName = $this->indexName('api_permissions', 'key_id');
        if ($this->hasIndexByName('api_permissions', $indexName)) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP INDEX %s',
                $this->wrap('api_permissions'),
                $this->wrap($indexName)
            ));
        }
    }

    private function ensureIndex(string $table, string $column): void
    {
        if ($this->hasIndexOnColumn($table, $column)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD INDEX %s (%s)',
            $this->wrap($table),
            $this->wrap($this->indexName($table, $column)),
            $this->wrap($column)
        ));
    }

    private function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        return (bool) DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    private function foreignKeysForColumn(string $table, string $column): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    private function hasIndexOnColumn(string $table, string $column): bool
    {
        return (bool) DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->exists();
    }

    private function hasIndexByName(string $table, string $index): bool
    {
        return (bool) DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function foreignName(string $table, string $column): string
    {
        return sprintf('%s_%s_foreign', $table, $column);
    }

    private function indexName(string $table, string $column): string
    {
        return sprintf('%s_%s_foreign_index', $table, $column);
    }

    private function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
