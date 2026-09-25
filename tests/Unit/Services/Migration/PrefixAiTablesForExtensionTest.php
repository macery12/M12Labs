<?php

namespace Everest\Tests\Unit\Services\Migration;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class PrefixAiTablesForExtensionTest extends TestCase
{
    private const TABLES = [
        'ai_conversations' => 'ext_ai_conversations',
        'ai_messages' => 'ext_ai_messages',
        'ai_tool_calls' => 'ext_ai_tool_calls',
        'ai_pending_actions' => 'ext_ai_pending_actions',
        'ai_turn_events' => 'ext_ai_turn_events',
        'ai_usage_logs' => 'ext_ai_usage_logs',
        'ai_budget_reservations' => 'ext_ai_budget_reservations',
    ];

    protected function tearDown(): void
    {
        foreach (array_merge(array_keys(self::TABLES), array_values(self::TABLES)) as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function testItRenamesEveryTableWithoutLosingDataAndCanRollBack(): void
    {
        foreach (self::TABLES as $table => $renamed) {
            Schema::dropIfExists($table);
            Schema::dropIfExists($renamed);
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('marker');
            });
            DB::table($table)->insert(['marker' => $table]);
        }

        $migration = require database_path('migrations/2026_09_19_000001_prefix_ai_tables_for_extension.php');
        $migration->up();

        foreach (self::TABLES as $old => $new) {
            $this->assertFalse(Schema::hasTable($old));
            $this->assertTrue(Schema::hasTable($new));
            $this->assertSame($old, DB::table($new)->value('marker'));
        }

        // A retry after MySQL committed DDL but before Laravel recorded the
        // migration must continue cleanly rather than trying to rename again.
        $migration->up();
        $migration->down();

        foreach (self::TABLES as $old => $new) {
            $this->assertTrue(Schema::hasTable($old));
            $this->assertFalse(Schema::hasTable($new));
            $this->assertSame($old, DB::table($old)->value('marker'));
        }
    }

    public function testItRefusesAmbiguousOldAndNewTables(): void
    {
        foreach (array_merge(array_keys(self::TABLES), array_values(self::TABLES)) as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }

        $migration = require database_path('migrations/2026_09_19_000001_prefix_ai_tables_for_extension.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('both tables exist');

        $migration->up();
    }
}
