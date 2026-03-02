<?php

namespace Everest\Tests\Unit\Models;

use Carbon\Carbon;
use Everest\Models\Server;
use Everest\Tests\TestCase;

class ServerDeletionScheduleTest extends TestCase
{
    public function test_is_not_scheduled_when_no_timestamp(): void
    {
        $server = new Server();

        $this->assertFalse($server->isDeletionScheduled());
    }

    public function test_is_scheduled_when_set_without_cancellation(): void
    {
        $server = new Server();
        $server->deletion_scheduled_at = Carbon::now();

        $this->assertTrue($server->isDeletionScheduled());
    }

    public function test_is_not_scheduled_when_canceled_after_schedule(): void
    {
        $server = new Server();
        $server->deletion_scheduled_at = Carbon::now()->subDay();
        $server->deletion_canceled_at = Carbon::now();

        $this->assertFalse($server->isDeletionScheduled());
    }

    public function test_is_scheduled_when_cancellation_is_before_schedule(): void
    {
        $server = new Server();
        $server->deletion_canceled_at = Carbon::now()->subDay();
        $server->deletion_scheduled_at = Carbon::now();

        $this->assertTrue($server->isDeletionScheduled());
    }
}
