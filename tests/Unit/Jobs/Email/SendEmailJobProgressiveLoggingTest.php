<?php

namespace Everest\Tests\Unit\Jobs\Email;

use Everest\Jobs\Email\SendEmailJob;
use Everest\Models\EmailLog;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\EmailManager;
use Everest\Services\Email\EmailResult;
use Everest\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class SendEmailJobProgressiveLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Enable email notifications for auth.password_reset
        EmailNotificationSetting::create([
            'template_key' => 'auth.password_reset',
            'enabled' => true,
            'rate_limit_exempt' => true,
        ]);
    }

    /** @test */
    public function it_creates_initial_log_entry_with_processing_status()
    {
        $correlationId = 'test-correlation-123';
        
        $emailManager = Mockery::mock(EmailManager::class);
        $emailManager->shouldReceive('sendFromTemplate')
            ->once()
            ->andReturn(EmailResult::success('msg-123'));
        
        $this->app->instance(EmailManager::class, $emailManager);

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'test@example.com',
            data: ['resetUrl' => 'https://example.com/reset'],
            userId: 1,
            correlationId: $correlationId
        );

        $job->handle($emailManager);

        // Check that a log entry was created
        $this->assertDatabaseHas('email_logs', [
            'correlation_id' => $correlationId,
            'to' => 'test@example.com',
            'template_key' => 'auth.password_reset',
            'user_id' => 1,
            'provider' => 'resend',
        ]);
    }

    /** @test */
    public function it_creates_only_one_log_entry_per_email_send()
    {
        $correlationId = 'test-correlation-456';
        
        $emailManager = Mockery::mock(EmailManager::class);
        $emailManager->shouldReceive('sendFromTemplate')
            ->once()
            ->andReturn(EmailResult::success('msg-456'));
        
        $this->app->instance(EmailManager::class, $emailManager);

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'test@example.com',
            data: ['resetUrl' => 'https://example.com/reset'],
            userId: 1,
            correlationId: $correlationId
        );

        $job->handle($emailManager);

        // Verify only one log entry exists for this correlation ID
        $logs = EmailLog::where('correlation_id', $correlationId)->get();
        $this->assertCount(1, $logs);
    }

    /** @test */
    public function it_updates_attempt_count_on_retries()
    {
        $correlationId = 'test-correlation-retry-789';
        
        // Create initial log entry (simulating first attempt)
        EmailLog::create([
            'correlation_id' => $correlationId,
            'to' => 'test@example.com',
            'subject' => 'Reset Your Password',
            'template_key' => 'auth.password_reset',
            'user_id' => 1,
            'provider' => 'resend',
            'status' => 'processing',
            'attempt_count' => 1,
            'success' => false,
        ]);

        $emailManager = Mockery::mock(EmailManager::class);
        $emailManager->shouldReceive('sendFromTemplate')
            ->once()
            ->andReturn(EmailResult::success('msg-789'));
        
        $this->app->instance(EmailManager::class, $emailManager);

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'test@example.com',
            data: ['resetUrl' => 'https://example.com/reset'],
            userId: 1,
            correlationId: $correlationId
        );

        // Simulate this being attempt 2
        $job->attempts = fn() => 2;

        $job->handle($emailManager);

        // Verify the log was updated (not duplicated)
        $logs = EmailLog::where('correlation_id', $correlationId)->get();
        $this->assertCount(1, $logs);
        
        // The attempt_count should be updated to 2
        $this->assertEquals(2, $logs->first()->attempt_count);
    }

    /** @test */
    public function it_updates_log_to_skipped_when_email_type_disabled()
    {
        $correlationId = 'test-correlation-disabled-999';
        
        // Disable the email notification
        EmailNotificationSetting::where('template_key', 'auth.password_reset')->update(['enabled' => false]);

        $emailManager = Mockery::mock(EmailManager::class);
        // Should NOT call sendFromTemplate when disabled
        $emailManager->shouldNotReceive('sendFromTemplate');
        
        $this->app->instance(EmailManager::class, $emailManager);

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'test@example.com',
            data: ['resetUrl' => 'https://example.com/reset'],
            userId: 1,
            correlationId: $correlationId
        );

        $job->handle($emailManager);

        // Verify the log was created with 'skipped' status
        $this->assertDatabaseHas('email_logs', [
            'correlation_id' => $correlationId,
            'status' => 'skipped',
        ]);
    }

    /** @test */
    public function it_updates_log_to_failed_on_validation_error()
    {
        $correlationId = 'test-correlation-validation-fail';
        
        $emailManager = Mockery::mock(EmailManager::class);
        // Should NOT call sendFromTemplate when validation fails
        $emailManager->shouldNotReceive('sendFromTemplate');
        
        $this->app->instance(EmailManager::class, $emailManager);

        // Mock EmailTypeRegistry to return validation errors
        $registry = Mockery::mock('alias:Everest\Services\Email\EmailTypeRegistry');
        $registry->shouldReceive('validateVariables')
            ->once()
            ->andReturn([[], ['resetUrl is required']]);

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'test@example.com',
            data: [], // Missing resetUrl
            userId: 1,
            correlationId: $correlationId
        );

        try {
            $job->handle($emailManager);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Expected
        }

        // Verify the log was created with 'failed' status
        $this->assertDatabaseHas('email_logs', [
            'correlation_id' => $correlationId,
            'status' => 'failed',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
