<?php

namespace Everest\Jobs\Email;

use Everest\Jobs\Job;
use Everest\Models\EmailLog;
use Everest\Models\EmailQuota;
use Everest\Models\DeferredEmail;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\EmailManager;
use Everest\Services\Email\EmailTypeRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEmailJob extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $templateKey,
        public string $recipient,
        public array $data,
        public ?int $userId = null,
        public ?string $correlationId = null
    ) {
    }

    /**
     * Execute the job.
     * 
     * NOTE: This job does NOT create EmailLog entries.
     * All logging is handled by EmailManager, which has access to complete context.
     */
    public function handle(EmailManager $emailManager): void
    {
        // Ensure we have a correlation_id for tracking
        if (!$this->correlationId) {
            $this->correlationId = \Illuminate\Support\Str::uuid()->toString();
        }

        Log::info('SendEmailJob: Starting', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'correlation_id' => $this->correlationId,
            'attempt' => $this->attempts(),
            'user_id' => $this->userId,
        ]);

        // Check if this email type is enabled
        if (!EmailNotificationSetting::isEnabled($this->templateKey)) {
            Log::info('SendEmailJob: Email type disabled', [
                'template_key' => $this->templateKey,
                'correlation_id' => $this->correlationId,
            ]);
            
            // Don't create logs here - let EmailManager handle it if called
            return;
        }

        // Check rate limiting (if user ID provided and not exempt)
        if ($this->userId && !EmailNotificationSetting::isRateLimitExempt($this->templateKey)) {
            $quota = EmailQuota::getOrCreateForUser($this->userId);
            
            if (!$quota->reserveQuota(1)) {
                // Quota exceeded - defer the email
                $nextAvailable = $quota->getNextAvailableTime();
                $reason = $quota->daily_limit && $quota->daily_sent >= $quota->daily_limit
                    ? 'daily_limit'
                    : 'monthly_limit';

                Log::info('SendEmailJob: Quota exceeded, deferring', [
                    'template_key' => $this->templateKey,
                    'user_id' => $this->userId,
                    'reason' => $reason,
                    'scheduled_at' => $nextAvailable,
                    'correlation_id' => $this->correlationId,
                ]);

                DeferredEmail::create([
                    'user_id' => $this->userId,
                    'template_key' => $this->templateKey,
                    'recipient' => $this->recipient,
                    'data' => $this->data,
                    'correlation_id' => $this->correlationId,
                    'reason' => $reason,
                    'scheduled_at' => $nextAvailable,
                ]);

                // Don't create logs here - let EmailManager handle it if called
                return;
            }
        }

        // Validate variables
        [$validData, $errors] = EmailTypeRegistry::validateVariables($this->templateKey, $this->data);
        
        if (!empty($errors)) {
            Log::error('SendEmailJob: Variable validation failed', [
                'template_key' => $this->templateKey,
                'errors' => $errors,
                'correlation_id' => $this->correlationId,
            ]);

            // Don't create logs here - throw exception and let it bubble up
            throw new \Exception('Variable validation failed: ' . implode(', ', $errors));
        }

        // Send the email (EmailManager will handle ALL logging)
        try {
            $startTime = microtime(true);
            
            $result = $emailManager->sendFromTemplate(
                $this->templateKey,
                $this->recipient,
                $validData,
                $this->correlationId,
                $this->userId
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if (!$result->success) {
                throw new \Exception($result->error ?? 'Unknown error');
            }

            Log::info('SendEmailJob: Email sent successfully', [
                'template_key' => $this->templateKey,
                'recipient' => $this->recipient,
                'message_id' => $result->messageId,
                'correlation_id' => $this->correlationId,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Exception $e) {
            Log::error('SendEmailJob: Failed to send email', [
                'template_key' => $this->templateKey,
                'recipient' => $this->recipient,
                'error' => $e->getMessage(),
                'correlation_id' => $this->correlationId,
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     * 
     * NOTE: Does NOT create log entries. All logging is handled by EmailManager.
     * This just logs the failure to application logs for debugging.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendEmailJob: Job failed permanently', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'error' => $exception->getMessage(),
            'correlation_id' => $this->correlationId,
            'user_id' => $this->userId,
        ]);
        
        // Don't create database logs here - EmailManager handles all logging
        // The exception should have already been logged by EmailManager if it
        // had all required fields (user_id, template_key, correlation_id)
    }
}
