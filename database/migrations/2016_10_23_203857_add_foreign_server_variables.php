<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddForeignServerVariables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('server_variables')) {
            return;
        }

        $relations = [
            ['column' => 'server_id', 'references' => 'servers'],
            ['column' => 'variable_id', 'references' => 'service_variables'],
        ];

        foreach ($relations as $relation) {
            $column = $relation['column'];
            if (!Schema::hasColumn('server_variables', $column) || $this->hasForeignKeyOnColumn('server_variables', $column)) {
                continue;
            }

            $this->ensureIndex('server_variables', $column);

            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (`id`)',
                $this->wrap('server_variables'),
                $this->wrap($this->foreignName('server_variables', $column)),
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
        if (!Schema::hasTable('server_variables')) {
            return;
        }

        foreach (['server_id', 'variable_id'] as $column) {
            if (!Schema::hasColumn('server_variables', $column)) {
                continue;
            }

            foreach ($this->foreignKeysForColumn('server_variables', $column) as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->wrap('server_variables'),
                    $this->wrap($foreignKey)
                ));
            }

            $indexName = $this->indexName('server_variables', $column);
            if ($this->hasIndexByName('server_variables', $indexName)) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP INDEX %s',
                    $this->wrap('server_variables'),
                    $this->wrap($indexName)
                ));
            }
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
