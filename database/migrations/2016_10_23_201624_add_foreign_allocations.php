<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddForeignAllocations extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('allocations')) {
            return;
        }

        $relations = [
            ['columns' => ['assigned_to', 'server_id'], 'references' => 'servers'],
            ['columns' => ['node', 'node_id'], 'references' => 'nodes'],
        ];

        foreach ($relations as $relation) {
            $column = $this->resolveColumn('allocations', $relation['columns']);

            if ($column === null || $this->hasForeignKeyOnColumn('allocations', $column)) {
                continue;
            }

            $this->ensureIndex('allocations', $column);

            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (`id`)',
                $this->wrap('allocations'),
                $this->wrap($this->foreignName('allocations', $column)),
                $this->wrap($column),
                $this->wrap($relation['references'])
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('allocations')) {
            return;
        }

        $columns = [
            ['assigned_to', 'server_id'],
            ['node', 'node_id'],
        ];

        foreach ($columns as $columnSet) {
            $column = $this->resolveColumn('allocations', $columnSet);

            if ($column === null) {
                continue;
            }

            foreach ($this->foreignKeysForColumn('allocations', $column) as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->wrap('allocations'),
                    $this->wrap($foreignKey)
                ));
            }

            $indexName = $this->indexName('allocations', $column);
            if ($this->hasIndexByName('allocations', $indexName)) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP INDEX %s',
                    $this->wrap('allocations'),
                    $this->wrap($indexName)
                ));
            }
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
