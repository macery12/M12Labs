<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Models\EmailDelivery;
use Everest\Models\EmailDeliveryAttempt;
use Everest\Services\Email\EmailDeliveryTracker;
use Everest\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmailDeliveryTrackerTest extends TestCase
{
    use RefreshDatabase;

    private EmailDeliveryTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new EmailDeliveryTracker();
    }

    public function testStartDeliveryCreatesRecord(): void
    {
        $correlationId = 'test-correlation-' . uniqid();
        
        $delivery = $this->tracker->startDelivery(
            correlationId: $correlationId,
            recipient: 'test@example.com',
            subject: 'Test Subject',
            templateKey: 'auth.password_reset',
            userId: 1,
            tags: [['name' => 'test', 'value' => 'value']]
        );

        $this->assertInstanceOf(EmailDelivery::class, $delivery);
        $this->assertEquals($correlationId, $delivery->correlation_id);
        $this->assertEquals('test@example.com', $delivery->recipient);
        $this->assertEquals('Test Subject', $delivery->subject);
        $this->assertEquals('auth.password_reset', $delivery->template_key);
        $this->assertEquals(1, $delivery->user_id);
        $this->assertEquals('queued', $delivery->status);
        $this->assertEquals(0, $delivery->attempts);
    }

    public function testStartAttemptCreatesRecordAndUpdatesDelivery(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        $attempt = $this->tracker->startAttempt($delivery, 1);

        $this->assertInstanceOf(EmailDeliveryAttempt::class, $attempt);
        $this->assertEquals($delivery->id, $attempt->delivery_id);
        $this->assertEquals(1, $attempt->attempt_number);
        $this->assertEquals('sending', $attempt->status);
        $this->assertFalse($attempt->success);

        // Check delivery status updated
        $delivery->refresh();
        $this->assertEquals('sending', $delivery->status);
        $this->assertNotNull($delivery->last_attempt_at);
    }

    public function testFinishAttemptSuccessUpdatesRecords(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        $attempt = $this->tracker->startAttempt($delivery, 1);

        $this->tracker->finishAttemptSuccess(
            attempt: $attempt,
            providerMessageId: 'msg-123',
            statusCode: 200,
            responsePayload: ['id' => 'msg-123']
        );

        $attempt->refresh();
        $this->assertTrue($attempt->success);
        $this->assertEquals('sent', $attempt->status);
        $this->assertEquals('msg-123', $attempt->provider_message_id);
        $this->assertEquals(200, $attempt->status_code);
        $this->assertNotNull($attempt->finished_at);
        $this->assertNotNull($attempt->duration_ms);

        // Check delivery synced
        $delivery->refresh();
        $this->assertEquals('sent', $delivery->status);
        $this->assertEquals(1, $delivery->attempts);
        $this->assertEquals('msg-123', $delivery->last_message_id);
        $this->assertEquals(200, $delivery->last_status_code);
        $this->assertNotNull($delivery->sent_at);
    }

    public function testFinishAttemptFailureUpdatesRecords(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        $attempt = $this->tracker->startAttempt($delivery, 1);

        $exception = new \Exception('Test error');
        $this->tracker->finishAttemptFailure(
            attempt: $attempt,
            error: 'Test error',
            statusCode: 500,
            exception: $exception
        );

        $attempt->refresh();
        $this->assertFalse($attempt->success);
        $this->assertEquals('failed', $attempt->status);
        $this->assertEquals('Test error', $attempt->error);
        $this->assertEquals(500, $attempt->status_code);
        $this->assertNotNull($attempt->finished_at);

        // Check delivery synced
        $delivery->refresh();
        $this->assertEquals('failed', $delivery->status);
        $this->assertEquals(1, $delivery->attempts);
        $this->assertEquals('Test error', $delivery->last_error);
        $this->assertEquals(500, $delivery->last_status_code);
    }

    public function testMarkDeferredUpdatesStatus(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        $nextAvailable = now()->addHour();
        $this->tracker->markDeferred($delivery, 'daily_limit', $nextAvailable);

        $delivery->refresh();
        $this->assertEquals('deferred', $delivery->status);
        $this->assertStringContainsString('daily_limit', $delivery->last_error);
    }

    public function testMarkSkippedUpdatesStatus(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        $this->tracker->markSkipped($delivery, 'Email type disabled');

        $delivery->refresh();
        $this->assertEquals('skipped', $delivery->status);
        $this->assertEquals('Email type disabled', $delivery->last_error);
    }

    public function testGenerateDebugBundle(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email',
            userId: 1
        );

        $attempt = $this->tracker->startAttempt($delivery, 1);
        $this->tracker->finishAttemptSuccess($attempt, 'msg-123', 200);

        $bundle = $this->tracker->generateDebugBundle($delivery);

        $this->assertArrayHasKey('delivery', $bundle);
        $this->assertArrayHasKey('attempts', $bundle);
        $this->assertCount(1, $bundle['attempts']);
        $this->assertEquals('test@example.com', $bundle['delivery']['recipient']);
        $this->assertEquals('msg-123', $bundle['attempts'][0]['provider_message_id']);
    }

    public function testMultipleAttemptsIncrementCounter(): void
    {
        $delivery = $this->tracker->startDelivery(
            correlationId: 'test-' . uniqid(),
            recipient: 'test@example.com',
            subject: 'Test',
            templateKey: 'test.email'
        );

        // First attempt - fail
        $attempt1 = $this->tracker->startAttempt($delivery, 1);
        $this->tracker->finishAttemptFailure($attempt1, 'Error 1', 500);

        $delivery->refresh();
        $this->assertEquals(1, $delivery->attempts);
        $this->assertEquals('failed', $delivery->status);

        // Second attempt - fail
        $attempt2 = $this->tracker->startAttempt($delivery, 2);
        $this->tracker->finishAttemptFailure($attempt2, 'Error 2', 500);

        $delivery->refresh();
        $this->assertEquals(2, $delivery->attempts);

        // Third attempt - success
        $attempt3 = $this->tracker->startAttempt($delivery, 3);
        $this->tracker->finishAttemptSuccess($attempt3, 'msg-123', 200);

        $delivery->refresh();
        $this->assertEquals(3, $delivery->attempts);
        $this->assertEquals('sent', $delivery->status);

        // Verify all attempts exist
        $this->assertEquals(3, $delivery->deliveryAttempts()->count());
    }
}
