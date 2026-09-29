<?php

namespace Everest\Tests\Unit\Console\Commands\Server;

use Everest\Tests\TestCase;
use Everest\Console\Commands\Server\BulkPowerActionCommand;

class BulkPowerActionCommandTest extends TestCase
{
    /**
     * With both --servers and --nodes, an ungrouped orWhereIn escaped the
     * `status is null` filter, so suspended and installing servers on the
     * listed nodes were power-cycled too.
     */
    public function testServersAndNodesStayInsideTheStatusFilter(): void
    {
        $command = $this->app->make(BulkPowerActionCommand::class);
        $query = (new \ReflectionMethod($command, 'getQueryBuilder'))->invoke($command, [1, 2], [3]);

        $this->assertSame(
            'select * from "servers" where "status" is null and ("id" in (?, ?) or "node_id" in (?))',
            $query->toSql()
        );
    }
}
