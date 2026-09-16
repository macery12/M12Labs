<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionMigrationService;
use Everest\Services\Extensions\ExtensionPhpSourceScanner;
use Everest\Services\Extensions\ExtensionMigrationSourceParser;

/**
 * What a package's migrations say they will do, read without running them.
 *
 * The old rule found `Schema::create` and nothing else. That was fine as a
 * prefix check for new tables and wrong as a description of a migration: an
 * update could drop a table, and both the install gate and the preview the
 * operator approved were silent about it. Dropping a table is not evasion of
 * the prefix rule — it is an operation the rule never looked at.
 *
 * The parse is deliberately source-level, so a table name built at runtime is
 * invisible to it. What it guarantees is that nothing written plainly is
 * missing from what an operator is shown.
 */
class ExtensionMigrationSourceParserTest extends TestCase
{
    private ExtensionMigrationSourceParser $parser;

    private string $tempRoot;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionMigrationSourceParser();
        $this->tempRoot = storage_path('framework/testing/migration-parser-' . uniqid());
        File::ensureDirectoryExists($this->tempRoot);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    private function migration(string $body): string
    {
        $path = $this->tempRoot . '/' . uniqid('2026_01_01_000000_') . '.php';
        File::put($path, "<?php\n\nreturn new class extends Migration {\n    public function up(): void\n    {\n" . $body . "\n    }\n};\n");

        return $path;
    }

    public function testEverySchemaVerbIsRead(): void
    {
        $operations = $this->parser->operations(<<<'PHP'
            <?php
            Schema::create('ext_demo_a', fn ($t) => $t->id());
            Schema::table('ext_demo_b', fn ($t) => $t->string('x'));
            Schema::drop('ext_demo_c');
            Schema::dropIfExists('ext_demo_d');
            Schema::rename('ext_demo_e', 'ext_demo_f');
            PHP);

        $this->assertSame(
            [
                ['verb' => 'create', 'table' => 'ext_demo_a', 'renameTo' => null],
                ['verb' => 'table', 'table' => 'ext_demo_b', 'renameTo' => null],
                ['verb' => 'drop', 'table' => 'ext_demo_c', 'renameTo' => null],
                ['verb' => 'dropIfExists', 'table' => 'ext_demo_d', 'renameTo' => null],
                ['verb' => 'rename', 'table' => 'ext_demo_e', 'renameTo' => 'ext_demo_f'],
            ],
            $operations,
        );
    }

    /**
     * A rename is the one verb that can move a table *out* of the namespace
     * while starting inside it, so the destination has to be read too.
     */
    public function testARenamesDestinationIsATableItTouches(): void
    {
        $operation = $this->parser->operations("<?php Schema::rename('ext_demo_a', 'users_backup');")[0];

        $this->assertSame(['ext_demo_a', 'users_backup'], $this->parser->tablesTouchedBy($operation));
    }

    /** A rule that reads a comment can be talked out of firing by a docblock. */
    public function testACommentIsNotASchemaChange(): void
    {
        $this->assertSame([], $this->parser->operations(<<<'PHP'
            <?php
            // This migration does not Schema::drop('users').
            /** Nor does it Schema::create('sessions'). */
            PHP));
    }

    /** And a rule that reads message strings can be made to fire by one. */
    public function testATableNamedInsideAStringIsNotASchemaChange(): void
    {
        $this->assertSame([], $this->parser->operations(<<<'PHP'
            <?php
            throw new RuntimeException("Refusing to Schema::drop('users') here.");
            PHP));
    }

    public function testStatementsItCannotReadAreCounted(): void
    {
        $source = <<<'PHP'
            <?php
            Schema::create('ext_demo_a', fn ($t) => $t->id());
            DB::statement('ALTER TABLE ext_demo_a ADD FULLTEXT(body)');
            DB::unprepared('DROP TABLE users');
            PHP;

        $this->assertCount(1, $this->parser->operations($source));
        $this->assertSame(2, $this->parser->rawStatementCount($source));
    }

    // The install gate.

    public function testDroppingACoreTableIsRefused(): void
    {
        $file = $this->migration("        Schema::dropIfExists('users');");

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Schema::dropIfExists on the table "users"');

        (new ExtensionMigrationService())->assertTablePrefixConvention('demo', [$file]);
    }

    public function testAlteringACoreTableIsRefused(): void
    {
        $file = $this->migration("        Schema::table('users', fn (\$t) => \$t->dropColumn('email'));");

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('outside the extension\'s allowed "ext_demo_" table namespace');

        (new ExtensionMigrationService())->assertTablePrefixConvention('demo', [$file]);
    }

    /** Starting inside the namespace is not the same as staying in it. */
    public function testRenamingOutOfTheNamespaceIsRefused(): void
    {
        $file = $this->migration("        Schema::rename('ext_demo_a', 'users_backup');");

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('"users_backup"');

        (new ExtensionMigrationService())->assertTablePrefixConvention('demo', [$file]);
    }

    public function testThePackagesOwnTablesArePermitted(): void
    {
        $file = $this->migration(<<<'PHP'
                    Schema::create('ext_demo_a', fn ($t) => $t->id());
                    Schema::table('ext_demo_a', fn ($t) => $t->string('x'));
                    Schema::rename('ext_demo_a', 'ext_demo_b');
                    Schema::dropIfExists('ext_demo_c');
            PHP);

        (new ExtensionMigrationService())->assertTablePrefixConvention('demo', [$file]);

        $this->assertTrue(true);
    }

    /** The scanner reads the same parser, so it cannot disagree with the gate. */
    public function testTheSourceScannerRefusesTheSameRename(): void
    {
        $path = $this->tempRoot . '/rename.php';
        File::put($path, "<?php\nSchema::rename('ext_demo_a', 'users_backup');\n");

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('users_backup');

        (new ExtensionPhpSourceScanner())->assertSafe('demo', [[
            'path' => 'app/Extensions/Packages/demo/database/migrations/rename.php',
            'sourcePath' => $path,
        ]]);
    }

    // What the operator is shown.

    public function testTheChangeSetIsGroupedByWhatItDoes(): void
    {
        $files = [
            $this->migration("        Schema::create('ext_demo_a', fn (\$t) => \$t->id());"),
            $this->migration(<<<'PHP'
                        Schema::table('ext_demo_a', fn ($t) => $t->string('x'));
                        Schema::dropIfExists('ext_demo_old');
                        Schema::rename('ext_demo_b', 'ext_demo_c');
                        DB::statement('ALTER TABLE ext_demo_a ADD FULLTEXT(body)');
                PHP),
        ];

        $changes = (new ExtensionMigrationService())->parseSchemaChanges($files);

        $this->assertSame(['ext_demo_a'], $changes['create']);
        $this->assertSame(['ext_demo_a'], $changes['alter']);
        $this->assertSame(['ext_demo_old'], $changes['drop']);
        $this->assertSame([['from' => 'ext_demo_b', 'to' => 'ext_demo_c']], $changes['rename']);
        $this->assertSame(1, $changes['rawStatements']);
    }

    /**
     * The failure this whole item is about: an update that destroys a table
     * used to produce a preview listing only what it added.
     */
    public function testADropIsNoLongerInvisible(): void
    {
        $changes = (new ExtensionMigrationService())->parseSchemaChanges([
            $this->migration("        Schema::dropIfExists('ext_demo_history');"),
        ]);

        $this->assertSame(['ext_demo_history'], $changes['drop']);
        $this->assertSame([], $changes['create']);
    }

    public function testAMissingFileIsSkippedRatherThanFatal(): void
    {
        $changes = (new ExtensionMigrationService())->parseSchemaChanges([$this->tempRoot . '/nope.php']);

        $this->assertSame(['create' => [], 'alter' => [], 'drop' => [], 'rename' => [], 'rawStatements' => 0], $changes);
    }
}
