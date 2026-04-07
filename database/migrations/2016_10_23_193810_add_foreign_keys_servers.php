<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddForeignKeysServers extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('servers')) {
            return;
        }

        $relations = [
            ['columns' => ['node', 'node_id'], 'references' => 'nodes'],
            ['columns' => ['owner', 'owner_id'], 'references' => 'users'],
            ['columns' => ['allocation', 'allocation_id'], 'references' => 'allocations'],
            ['columns' => ['service', 'service_id'], 'references' => 'services'],
            ['columns' => ['option', 'option_id'], 'references' => 'service_options'],
        ];

        foreach ($relations as $relation) {
            $column = $this->resolveColumn('servers', $relation['columns']);

            if ($column === null || $this->hasForeignKeyOnColumn('servers', $column)) {
                continue;
            }

            $this->ensureIndex('servers', $column);

            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (`id`)',
                $this->wrap('servers'),
                $this->wrap($this->foreignName('servers', $column)),
                $this->wrap($column),
                $this->wrap($relation['references'])
            ));
        }

        if (!Schema::hasColumn('servers', 'deleted_at')) {
            DB::statement(sprintf('ALTER TABLE %s ADD COLUMN `deleted_at` TIMESTAMP NULL', $this->wrap('servers')));
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
            ['node', 'node_id'],
            ['owner', 'owner_id'],
            ['allocation', 'allocation_id'],
            ['service', 'service_id'],
            ['option', 'option_id'],
        ];

        foreach ($columns as $columnSet) {
            $column = $this->resolveColumn('servers', $columnSet);

            if ($column === null) {
                continue;
            }

            foreach ($this->foreignKeysForColumn('servers', $column) as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->wrap('servers'),
                    $this->wrap($foreignKey)
                ));
            }

            $indexName = $this->indexName('servers', $column);
            if ($this->hasIndexByName('servers', $indexName)) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP INDEX %s',
                    $this->wrap('servers'),
                    $this->wrap($indexName)
                ));
            }
        }

        if (Schema::hasColumn('servers', 'deleted_at')) {
            DB::statement(sprintf('ALTER TABLE %s DROP COLUMN `deleted_at`', $this->wrap('servers')));
        }
    }

    private function resolveColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
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
