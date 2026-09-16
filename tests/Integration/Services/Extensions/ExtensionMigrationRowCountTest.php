<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionMigrationService;

/**
 * Row counts behind the "this update will delete these tables" warning.
 *
 * The number is the whole point of showing it: "ext_demo_history will be
 * dropped" and "ext_demo_history (14,208 rows) will be dropped" are different
 * decisions. The distinction this pins is between a table with no rows and a
 * table that does not exist — on a confirmation screen those must not read the
 * same, so an absent table has no count rather than a count of zero.
 */
class ExtensionMigrationRowCountTest extends IntegrationTestCase
{
    private ExtensionMigrationService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new ExtensionMigrationService();

        Schema::create('ext_rowcount_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('ext_rowcount_empty', function (Blueprint $table) {
            $table->id();
        });

        DB::table('ext_rowcount_items')->insert([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('ext_rowcount_items');
        Schema::dropIfExists('ext_rowcount_empty');

        parent::tearDown();
    }

    public function testAnExistingTableIsCounted(): void
    {
        $counts = $this->service->rowCountsFor('rowcount', ['ext_rowcount_items']);

        $this->assertSame(['ext_rowcount_items' => 3], $counts);
    }

    /** Empty is a number. Absent is not. */
    public function testAnEmptyTableCountsZeroAndAMissingOneIsAbsent(): void
    {
        $counts = $this->service->rowCountsFor('rowcount', ['ext_rowcount_empty', 'ext_rowcount_gone']);

        $this->assertSame(0, $counts['ext_rowcount_empty']);
        $this->assertArrayNotHasKey('ext_rowcount_gone', $counts);
    }

    /**
     * The name is interpolated, because a table name cannot be a bound
     * parameter. Anything the panel has not seen in information_schema under
     * this extension's prefix never reaches the query.
     */
    public function testATableOutsideTheExtensionsPrefixIsNeverQueried(): void
    {
        $this->assertSame([], $this->service->rowCountsFor('rowcount', ['users']));
    }

    public function testAnInjectedNameIsNeverQueried(): void
    {
        $this->assertSame([], $this->service->rowCountsFor('rowcount', ['ext_rowcount_items`; DROP TABLE users; --']));

        $this->assertTrue(Schema::hasTable('users'));
    }
}
