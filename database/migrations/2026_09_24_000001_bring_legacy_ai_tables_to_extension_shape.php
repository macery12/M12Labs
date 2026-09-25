<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Finish the reshape an install may have missed before its AI tables were
 * handed to the extension.
 *
 * `2026_07_20_000022_create_ai_tables` built three tables in their first shape
 * and `2026_08_12_000001_extend_ai_core_tables` used to reshape them for the
 * agent. When the AI module left core, 08_12 lost that half (it now only adds
 * `tickets.server_id`) and 2026_09_19 renamed the tables to `ext_ai_*`. An
 * install that had run 07_20 but not the old 08_12 — anything last migrated
 * between 2026-07-20 and 2026-08-22 — therefore hands the extension its tables
 * in the first shape, and the extension's guarded create sees they exist and
 * leaves them: no `scope`, `assist` or `turn_id`, and no `tool` message role.
 *
 * This applies the missing reshape to whatever is there, column by column, so
 * it is a no-op on every install that did run the old 08_12 and on every fresh
 * one (which has no such tables until the extension creates them correctly).
 * No down(): it only brings tables to the shape the extension already expects,
 * and the tables are the extension's to remove.
 */
return new class () extends Migration {
    public function up(): void
    {
        $this->conversations();
        $this->messages();
        $this->usageLogs();
    }

    public function down(): void
    {
    }

    private function conversations(): void
    {
        $table = 'ext_ai_conversations';
        if (!Schema::hasTable($table)) {
            return;
        }

        // Admin conversations have no server, so the column must allow null.
        $this->relaxServerUuid($table);

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (!Schema::hasColumn($table, 'scope')) {
                $blueprint->string('scope', 16)->default('server')->after('server_uuid');
            }
            if (!Schema::hasColumn($table, 'redactions')) {
                $blueprint->json('redactions')->nullable()->after('title');
            }
            if (!Schema::hasColumn($table, 'assist')) {
                $blueprint->json('assist')->nullable()->after('redactions');
            }
        });

        $this->addIndexOnce($table, ['user_id', 'scope'], 'ext_ai_conversations_user_id_scope_index');
    }

    private function messages(): void
    {
        $table = 'ext_ai_messages';
        if (!Schema::hasTable($table)) {
            return;
        }

        // Before the columns, and keyed on the first of them: the columns land
        // in one statement, so if this succeeds and they fail, a rerun sees them
        // still missing and repeats a harmless change rather than skipping it.
        if (!Schema::hasColumn($table, 'tool_calls')) {
            // A turn writes several messages inside one second and the
            // transcript has to keep their order.
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('created_at', 3)->useCurrent()->change();
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (!Schema::hasColumn($table, 'tool_calls')) {
                $blueprint->json('tool_calls')->nullable()->after('content');
            }
            if (!Schema::hasColumn($table, 'tool_call_id')) {
                $blueprint->string('tool_call_id', 128)->nullable()->after('tool_calls');
            }
            if (!Schema::hasColumn($table, 'tool_name')) {
                $blueprint->string('tool_name', 64)->nullable()->after('tool_call_id');
            }
            if (!Schema::hasColumn($table, 'step')) {
                $blueprint->unsignedSmallInteger('step')->nullable()->after('tool_name');
            }
        });

        $this->setEnum($table, 'role', ['user', 'assistant', 'system', 'tool'], nullable: false, default: null);
    }

    private function usageLogs(): void
    {
        $table = 'ext_ai_usage_logs';
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->setEnum($table, 'status', ['success', 'error', 'suspended', 'running', 'cancelled'], nullable: false, default: 'success');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (!Schema::hasColumn($table, 'turn_id')) {
                $blueprint->uuid('turn_id')->nullable()->after('conversation_id');
            }
            if (!Schema::hasColumn($table, 'step')) {
                $blueprint->unsignedSmallInteger('step')->nullable()->after('turn_id');
            }
            if (!Schema::hasColumn($table, 'tool_calls_count')) {
                $blueprint->unsignedSmallInteger('tool_calls_count')->default(0)->after('step');
            }
            if (!Schema::hasColumn($table, 'heartbeat_at')) {
                $blueprint->timestamp('heartbeat_at')->nullable()->after('error_message');
            }
            if (!Schema::hasColumn($table, 'deadline_at')) {
                $blueprint->timestamp('deadline_at')->nullable()->after('heartbeat_at');
            }
            if (!Schema::hasColumn($table, 'cancel_requested_at')) {
                $blueprint->timestamp('cancel_requested_at')->nullable()->after('deadline_at');
            }
        });

        $this->addIndexOnce($table, ['turn_id'], 'ext_ai_usage_logs_turn_unique', unique: true);
        $this->addIndexOnce($table, ['user_id', 'created_at'], 'ext_ai_usage_logs_user_created_index');
        $this->addIndexOnce($table, ['user_id', 'status', 'id'], 'ext_ai_usage_logs_user_status_id_index');
    }

    /**
     * Make `server_uuid` nullable, keeping its foreign key.
     *
     * The key is found by column rather than by name: a table that reached
     * here through 2026_09_19's rename still carries its constraints under the
     * `ai_conversations_*` names, which the conventional name would miss.
     */
    private function relaxServerUuid(string $table): void
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'server_uuid');
        if ($column === null || $column['nullable']) {
            return;
        }

        $foreign = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === ['server_uuid']);

        if ($foreign !== null && $this->isMysql()) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->char('server_uuid', 36)->nullable()->change());

        if ($foreign !== null && $this->isMysql()) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('server_uuid')
                ->references('uuid')->on('servers')->cascadeOnDelete());
        }
    }

    /**
     * Widen an enum to the values the extension writes.
     *
     * MySQL gets the enum widened in place. Elsewhere an enum is a CHECK
     * constraint that cannot be widened, so the column becomes a short string,
     * as the original reshape did.
     *
     * @param list<string> $values
     */
    private function setEnum(string $table, string $column, array $values, bool $nullable, ?string $default): void
    {
        if (!$this->isMysql()) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string($column, 16)
                ->nullable($nullable)->default($default)->change());

            return;
        }

        $quoted = implode(',', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` ENUM(%s) %s%s',
            $table,
            $column,
            $quoted,
            $nullable ? 'NULL' : 'NOT NULL',
            $default === null ? '' : ' DEFAULT ' . DB::getPdo()->quote($default),
        ));
    }

    /**
     * Add an index unless one already covers exactly these columns, under any
     * name — a renamed table keeps the `ai_*` names its indexes were built with.
     *
     * @param list<string> $columns
     */
    private function addIndexOnce(string $table, array $columns, string $name, bool $unique = false): void
    {
        $exists = collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => $index['columns'] === $columns && (!$unique || $index['unique']),
        );

        if ($exists) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $unique
            ? $blueprint->unique($columns, $name)
            : $blueprint->index($columns, $name));
    }

    private function isMysql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
