<?php

namespace Everest\Tests\Unit\Commands\Billing;

use Everest\Models\User;
use Everest\Models\Billing\Order;
use Everest\Tests\TestCase;
use Everest\Console\Commands\Billing\CleanupOrdersCommand;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CleanupOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that pending orders older than 7 days are marked as expired.
     */
    public function testPendingOrdersOlderThan7DaysAreExpired()
    {
        Carbon::setTestNow(Carbon::now());

        // Create a user
        $user = User::factory()->create();

        // Create a pending order that is 8 days old
        $oldOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'created_at' => Carbon::now()->subDays(8),
        ]);

        // Create a pending order that is 5 days old (should not be expired)
        $newOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        // Run the command
        $this->artisan(CleanupOrdersCommand::class);

        // Assert the old order is now expired
        $this->assertDatabaseHas('orders', [
            'id' => $oldOrder->id,
            'status' => Order::STATUS_EXPIRED,
        ]);

        // Assert the new order is still pending
        $this->assertDatabaseHas('orders', [
            'id' => $newOrder->id,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    /**
     * Test that expired orders older than 7 days are deleted.
     */
    public function testExpiredOrdersOlderThan7DaysAreDeleted()
    {
        Carbon::setTestNow(Carbon::now());

        // Create a user
        $user = User::factory()->create();

        // Create an expired order that was updated 8 days ago
        $oldExpiredOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_EXPIRED,
            'updated_at' => Carbon::now()->subDays(8),
        ]);

        // Create an expired order that was updated 5 days ago (should not be deleted)
        $newExpiredOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_EXPIRED,
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        // Run the command
        $this->artisan(CleanupOrdersCommand::class);

        // Assert the old expired order is deleted
        $this->assertDatabaseMissing('orders', [
            'id' => $oldExpiredOrder->id,
        ]);

        // Assert the new expired order still exists
        $this->assertDatabaseHas('orders', [
            'id' => $newExpiredOrder->id,
            'status' => Order::STATUS_EXPIRED,
        ]);
    }

    /**
     * Test that orders for non-existent users are deleted immediately.
     */
    public function testOrdersForNonExistentUsersAreDeleted()
    {
        Carbon::setTestNow(Carbon::now());

        // Create an order with a non-existent user ID
        $order = Order::factory()->create([
            'user_id' => 99999, // Non-existent user
            'status' => Order::STATUS_PENDING,
        ]);

        // Run the command
        $this->artisan(CleanupOrdersCommand::class);

        // Assert the order is deleted
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
        ]);
    }

    /**
     * Test that pending orders exactly 7 days old are marked as expired.
     */
    public function testPendingOrdersExactly7DaysOldAreExpired()
    {
        Carbon::setTestNow(Carbon::now());

        // Create a user
        $user = User::factory()->create();

        // Create a pending order that is exactly 7 days old
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'created_at' => Carbon::now()->subDays(7),
        ]);

        // Run the command
        $this->artisan(CleanupOrdersCommand::class);

        // Assert the order is now expired
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_EXPIRED,
        ]);
    }

    /**
     * Test that processed and failed orders are not affected.
     */
    public function testProcessedAndFailedOrdersAreNotAffected()
    {
        Carbon::setTestNow(Carbon::now());

        // Create a user
        $user = User::factory()->create();

        // Create a processed order that is 10 days old
        $processedOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PROCESSED,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        // Create a failed order that is 10 days old
        $failedOrder = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_FAILED,
            'created_at' => Carbon::now()->subDays(10),
        ]);

        // Run the command
        $this->artisan(CleanupOrdersCommand::class);

        // Assert both orders still exist with their original status
        $this->assertDatabaseHas('orders', [
            'id' => $processedOrder->id,
            'status' => Order::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $failedOrder->id,
            'status' => Order::STATUS_FAILED,
        ]);
    }
}
