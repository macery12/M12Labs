<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionMigrationService;

/**
 * Reverting a failed install or update touches only what that operation ran.
 *
 * It used to revert "the last batch". A migration that throws writes no
 * record, so when the first one of a run failed the last batch was whichever
 * came before — and after a keep-data uninstall and a reinstall, that is the
 * extension's own first install, whose down() drops the very tables the
 * uninstall promised to keep.
 */
class ExtensionMigrationRollbackTest extends IntegrationTestCase
{
    private const ID = 'rbdemo';

    private ExtensionMigrationService $service;

    private string $originalBasePath;

    private string $fixtureBasePath;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new ExtensionMigrationService();

        // Package files live under base_path(), and this box's base path is the
        // live panel's. A temp root keeps the fixture out of its package tree.
        $this->originalBasePath = base_path();
        $this->fixtureBasePath = sys_get_temp_dir() . '/m12labs-migration-rollback-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->fixtureBasePath . '/' . $this->service->migrationPath(self::ID));
        $this->app->setBasePath($this->fixtureBasePath);
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('ext_rbdemo_kept');
        Schema::dropIfExists('ext_rbdemo_new');
        DB::table('migrations')->where('migration', 'like', '2026_01_0%_rbdemo_%')->delete();

        $this->app->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->fixtureBasePath);

        parent::tearDown();
    }

    private function writeMigration(string $name, string $table): string
    {
        $path = base_path($this->service->migrationPath(self::ID)) . '/' . $name . '.php';
        File::put($path, <<<PHP
            <?php

            use Illuminate\\Support\\Facades\\Schema;
            use Illuminate\\Database\\Migrations\\Migration;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('{$table}', fn (\$t) => \$t->id());
                }

                public function down(): void
                {
                    Schema::dropIfExists('{$table}');
                }
            };
            PHP);

        return $path;
    }

    /** The regression: nothing new ran, so nothing is reverted. */
    public function testAFailureThatRanNothingLeavesTheEarlierInstallAlone(): void
    {
        $this->writeMigration('2026_01_01_000001_rbdemo_kept', 'ext_rbdemo_kept');
        $this->service->run(self::ID);

        $result = $this->service->rollbackApplied(self::ID, []);

        $this->assertSame([], $result['rolledBack']);
        $this->assertTrue(Schema::hasTable('ext_rbdemo_kept'));
    }

    public function testOnlyTheNamedMigrationsAreReverted(): void
    {
        $this->writeMigration('2026_01_01_000001_rbdemo_kept', 'ext_rbdemo_kept');
        $this->service->run(self::ID);
        $applied = $this->writeMigration('2026_01_02_000001_rbdemo_new', 'ext_rbdemo_new');
        $this->service->run(self::ID);

        $result = $this->service->rollbackApplied(self::ID, [basename($applied)]);

        $this->assertSame(['2026_01_02_000001_rbdemo_new'], $result['rolledBack']);
        $this->assertFalse(Schema::hasTable('ext_rbdemo_new'));
        $this->assertTrue(Schema::hasTable('ext_rbdemo_kept'));
    }

    /**
     * After a keep-data uninstall the files are gone and the records are not.
     * The install preview reads the records by name, so a reinstall is not
     * shown recreating tables it will reattach to.
     */
    public function testRecordsAreFoundWithoutThePackagesFiles(): void
    {
        DB::table('migrations')->insert(['migration' => '2026_01_01_000001_rbdemo_kept', 'batch' => 1]);

        $this->assertSame([], $this->service->ranMigrationNames(self::ID));
        $this->assertSame(
            ['2026_01_01_000001_rbdemo_kept'],
            $this->service->recordedAsRan(['/tmp/extract/2026_01_01_000001_rbdemo_kept.php', '/tmp/extract/2026_01_03_000001_rbdemo_later.php']),
        );
    }
}
