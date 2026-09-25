<?php

namespace Everest\Tests\Unit\Services\Migration;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * An install that renamed its AI tables in their first shape — having run the
 * original create but never the agent reshape — is brought to the shape the
 * extension expects, without losing rows, and without disturbing an install
 * that is already there.
 */
class BringLegacyAiTablesToExtensionShapeTest extends TestCase
{
    private const TABLES = ['ext_ai_usage_logs', 'ext_ai_messages', 'ext_ai_conversations', 'servers', 'users'];

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_24_000001_bring_legacy_ai_tables_to_extension_shape.php');
    }

    /**
     * The tables exactly as 2026_07_20_000022_create_ai_tables built them, under
     * the names 2026_09_19 renamed them to.
     */
    private function legacyTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', fn (Blueprint $table) => $table->increments('id'));
        Schema::create('servers', function (Blueprint $table): void {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
        });

        Schema::create('ext_ai_conversations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->char('server_uuid', 36);
            $table->string('title', 255)->default('New conversation');
            $table->boolean('is_saved')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'server_uuid']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('server_uuid')->references('uuid')->on('servers')->onDelete('cascade');
        });

        Schema::create('ext_ai_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id')->index();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ext_ai_usage_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->char('server_uuid', 36)->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('model', 100);
            $table->enum('status', ['success', 'error'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function testLegacyTablesGainEveryColumnTheExtensionReadsAndKeepTheirRows(): void
    {
        $this->legacyTables();
        DB::table('users')->insert(['id' => 1]);
        DB::table('servers')->insert(['id' => 1, 'uuid' => str_repeat('a', 36)]);
        DB::table('ext_ai_conversations')->insert(['id' => 5, 'user_id' => 1, 'server_uuid' => str_repeat('a', 36)]);
        DB::table('ext_ai_messages')->insert(['conversation_id' => 5, 'role' => 'user', 'content' => 'hello']);
        DB::table('ext_ai_usage_logs')->insert(['model' => 'm', 'status' => 'success']);

        $this->migration()->up();

        foreach (['scope', 'redactions', 'assist'] as $column) {
            $this->assertTrue(Schema::hasColumn('ext_ai_conversations', $column), $column);
        }
        foreach (['tool_calls', 'tool_call_id', 'tool_name', 'step'] as $column) {
            $this->assertTrue(Schema::hasColumn('ext_ai_messages', $column), $column);
        }
        foreach (['turn_id', 'step', 'tool_calls_count', 'heartbeat_at', 'deadline_at', 'cancel_requested_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('ext_ai_usage_logs', $column), $column);
        }

        $this->assertTrue(collect(Schema::getColumns('ext_ai_conversations'))->firstWhere('name', 'server_uuid')['nullable']);
        $this->assertSame('server', DB::table('ext_ai_conversations')->value('scope'));
        $this->assertSame('hello', DB::table('ext_ai_messages')->value('content'));

        // What the agent writes: an admin conversation with no server, a tool
        // message, a running turn.
        DB::table('ext_ai_conversations')->insert(['user_id' => 1, 'server_uuid' => null, 'scope' => 'admin']);
        DB::table('ext_ai_messages')->insert(['conversation_id' => 5, 'role' => 'tool', 'content' => '{}', 'tool_name' => 'files_read']);
        DB::table('ext_ai_usage_logs')->insert(['model' => 'm', 'status' => 'running', 'turn_id' => '00000000-0000-4000-8000-000000000001']);

        $unique = collect(Schema::getIndexes('ext_ai_usage_logs'))->firstWhere('columns', ['turn_id']);
        $this->assertTrue($unique['unique'] ?? false, 'turn_id must be unique: a turn is claimed by id.');
    }

    public function testRunningTwiceChangesNothingMore(): void
    {
        $this->legacyTables();

        $this->migration()->up();
        $indexes = count(Schema::getIndexes('ext_ai_usage_logs'));

        $this->migration()->up();

        $this->assertSame($indexes, count(Schema::getIndexes('ext_ai_usage_logs')));
    }

    /** A fresh install has no AI tables until the extension creates them. */
    public function testItIsANoOpWithoutTheTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('ext_ai_conversations'));
    }
}
