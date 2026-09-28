<?php

namespace Everest\Tests\Integration\Models;

use Everest\Models\Task;
use Everest\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Exceptions\Model\DataValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Every save used to re-run a model's whole rule set, so renaming a server
 * re-checked its owner, node, allocation, nest and egg with a SELECT each. An
 * update now validates only what changed -- except on a model whose rules read
 * other fields, where a change to one field can invalidate another.
 */
class UpdateValidationTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testAnUpdateValidatesOnlyTheChangedColumns(): void
    {
        $server = $this->createServerModel();

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            // exists: and unique: both validate with a count(*) aggregate.
            if (str_contains(strtolower($query->sql), 'count(*) as aggregate')) {
                ++$lookups;
            }
        });

        $server->update(['name' => 'Renamed']);

        $this->assertSame(0, $lookups);
        $this->assertSame('Renamed', $server->fresh()->name);
    }

    public function testAChangedColumnIsStillValidated(): void
    {
        $server = $this->createServerModel();

        $this->expectException(DataValidationException::class);

        $server->update(['node_id' => 999999]);
    }

    /**
     * Task's payload is `required_unless:action,backup`: switching a backup task
     * to a command must re-check the payload column nobody touched.
     */
    public function testAModelWithCrossFieldRulesKeepsFullValidation(): void
    {
        $server = $this->createServerModel();
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'action' => 'backup', 'payload' => '']);

        $this->expectException(DataValidationException::class);

        $task->update(['action' => 'command']);
    }
}
