<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionForeignKeyPolicy;
use Everest\Services\Extensions\ExtensionMigrationService;
use Everest\Services\Extensions\ExtensionPhpSourceScanner;
use Everest\Services\Extensions\ExtensionMigrationSourceParser;

class ExtensionForeignKeyPolicyTest extends TestCase
{
    private ExtensionMigrationSourceParser $parser;

    private ExtensionForeignKeyPolicy $policy;

    private string $tempRoot;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionMigrationSourceParser();
        $this->policy = new ExtensionForeignKeyPolicy($this->parser);
        $this->tempRoot = storage_path('framework/testing/foreign-key-policy-' . uniqid());
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function testParserReadsExplicitAndShorthandForeignKeys(): void
    {
        $references = $this->parser->foreignKeyReferences(<<<'PHP'
            <?php
            $table->foreign('server_uuid')->references('uuid')->on('servers')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'demo_user_fk')->cascadeOnDelete();
            $table->foreignUuid('node_id')->constrained(table: 'nodes', column: 'id')->onDelete('SET NULL');
            PHP);

        $this->assertSame([
            ['localColumn' => 'server_uuid', 'table' => 'servers', 'referencedColumn' => 'uuid', 'onDelete' => 'set null'],
            ['localColumn' => 'user_id', 'table' => 'users', 'referencedColumn' => 'id', 'onDelete' => 'cascade'],
            ['localColumn' => 'node_id', 'table' => 'nodes', 'referencedColumn' => 'id', 'onDelete' => 'set null'],
        ], $references);
    }

    public function testImplicitConstrainedTableIsInferredAndBareForeignIdIsOnlyAColumn(): void
    {
        $references = $this->parser->foreignKeyReferences(<<<'PHP'
            <?php
            $table->foreignId('user_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            PHP);

        $this->assertSame([
            ['localColumn' => 'user_id', 'table' => 'users', 'referencedColumn' => 'id', 'onDelete' => 'cascade'],
        ], $references);
    }

    public function testCommentsAndStringsCannotInventForeignKeys(): void
    {
        $this->assertSame([], $this->parser->foreignKeyReferences(<<<'PHP'
            <?php
            // $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $message = "$table->foreign('server_id')->references('id')->on('servers')";
            PHP));
    }

    public function testOwnTablesAndStableCoreIdentitiesWithSafeDeletionAreAllowed(): void
    {
        $violations = $this->policy->violations('demo', <<<'PHP'
            <?php
            $table->foreign('parent_id')->references('id')->on('ext_demo_things');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_uuid')->references('uuid')->on('servers')->nullOnDelete();
            PHP);

        $this->assertSame([], $violations);
    }

    public function testCrossExtensionForeignKeyIsRefused(): void
    {
        $violations = $this->policy->violations('demo', <<<'PHP'
            <?php
            $table->foreign('record_id')->references('id')->on('ext_other_records')->cascadeOnDelete();
            PHP);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('cross-extension foreign keys are prohibited', $violations[0]);
    }

    public function testUndeclaredCoreTargetOrColumnIsRefused(): void
    {
        $this->assertStringContainsString(
            'not a stable core foreign-key target',
            $this->policy->violations('demo', <<<'PHP'
                <?php
                $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
                PHP)[0],
        );

        $this->assertStringContainsString(
            'not a stable core foreign-key target',
            $this->policy->violations('demo', <<<'PHP'
                <?php
                $table->foreign('email')->references('email')->on('users')->cascadeOnDelete();
                PHP)[0],
        );
    }

    public function testCoreReferenceMustNotBlockCoreDeletion(): void
    {
        $violations = $this->policy->violations('demo', <<<'PHP'
            <?php
            $table->foreignId('user_id')->constrained('users');
            PHP);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('without CASCADE or SET NULL', $violations[0]);
    }

    public function testDynamicForeignKeyTargetIsRefused(): void
    {
        $violations = $this->policy->violations('demo', <<<'PHP'
            <?php
            $table->foreign('owner_id')->references('id')->on($ownerTable)->cascadeOnDelete();
            PHP);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('table and columns are not literal', $violations[0]);

        $modelDerived = $this->policy->violations('demo', <<<'PHP'
            <?php
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignUuidFor(Server::class)->constrained()->cascadeOnDelete();
            $table->foreignUlidFor(User::class)->constrained()->cascadeOnDelete();
            PHP);

        $this->assertCount(3, $modelDerived);
        $this->assertStringContainsString('table and columns are not literal', $modelDerived[0]);
    }

    public function testMigrationGateAndPackageScannerApplyTheSamePolicy(): void
    {
        $source = <<<'PHP'
            <?php
            Schema::create('ext_demo_things', function ($table) {
                $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            });
            PHP;
        $file = $this->tempRoot . '/2026_01_01_000000_create_things.php';
        File::put($file, $source);

        try {
            (new ExtensionMigrationService())->assertMigrationConventions('demo', [$file]);
            $this->fail('Expected the migration gate to refuse the foreign key.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('not a stable core foreign-key target', $exception->getMessage());
        }

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not a stable core foreign-key target');

        (new ExtensionPhpSourceScanner())->assertSafe('demo', [[
            'path' => 'app/Extensions/Packages/demo/database/migrations/' . basename($file),
            'sourcePath' => $file,
        ]]);
    }
}
