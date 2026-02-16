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
     */
    public function handle(EmailManager $emailManager): void
    {
        // Ensure we have a correlation_id and store it for use in failed() method
        if (!$this->correlationId) {
            $this->correlationId = \Illuminate\Support\Str::uuid()->toString();
        }

        Log::info('SendEmailJob: Starting', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'correlation_id' => $this->correlationId,
            'attempt' => $this->attempts(),
        ]);

        // Get subject for template key (for early log)
        $subject = $this->getSubjectForTemplate($this->templateKey);

        // Create or update the initial log entry with status='processing'
        // This ensures we have a single log row from the start
        $log = EmailLog::updateOrCreate(
            [
                'correlation_id' => $this->correlationId,
            ],
            [
                'to' => $this->recipient,
                'subject' => $subject,
                'template_key' => $this->templateKey,
                'user_id' => $this->userId,
                'provider' => 'resend',
                'status' => 'processing',
                'attempt_count' => $this->attempts(),
                'success' => false,
            ]
        );

        // Check if this email type is enabled
        if (!EmailNotificationSetting::isEnabled($this->templateKey)) {
            Log::info('SendEmailJob: Email type disabled', [
                'template_key' => $this->templateKey,
                'correlation_id' => $this->correlationId,
            ]);
            
            // Update log to skipped status
            $log->update([
                'status' => 'skipped',
                'error' => 'Email type disabled in notification settings',
            ]);
            
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

                // Update log to deferred status
                $log->update([
                    'status' => 'deferred',
                    'error' => "Quota exceeded: {$reason}",
                ]);

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

            // Update log to failed status
            $log->update([
                'status' => 'failed',
                'error' => 'Variable validation failed: ' . implode(', ', $errors),
            ]);

            throw new \Exception('Variable validation failed: ' . implode(', ', $errors));
        }

        // Send the email (EmailManager will update the log with final status)
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
     * Updates the log entry to failed status. The log entry should already exist
     * from the handle() method, and correlationId is guaranteed to be set.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendEmailJob: Job failed permanently', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'error' => $exception->getMessage(),
            'correlation_id' => $this->correlationId,
        ]);
        
        // Update the existing log entry to failed status
        // correlationId is guaranteed to be set (either from constructor or generated in handle())
        if ($this->correlationId) {
            EmailLog::where('correlation_id', $this->correlationId)->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get email subject for a template key.
     */
    private function getSubjectForTemplate(string $templateKey): string
    {
        $subjects = [
            'auth.account_created' => 'Welcome to Your Account',
            'auth.email_verification' => 'Verify Your Email Address',
            'auth.password_reset' => 'Reset Your Password',
            'auth.password_changed' => 'Your Password Has Been Changed',
            'auth.new_login' => 'New Login Detected',
            'auth.account_locked' => 'Your Account Has Been Suspended',
            'auth.account_unsuspended' => 'Your Account Has Been Restored',
            'auth.2fa_enabled' => 'Two-Factor Authentication Enabled',
            'auth.2fa_disabled' => 'Two-Factor Authentication Disabled',
            'server.created' => 'Your Server Has Been Created',
            'server.suspended' => 'Your Server Has Been Suspended',
            'server.unsuspended' => 'Your Server Has Been Unsuspended',
            'server.expiring_soon' => 'Your Server Is Expiring Soon',
            'billing.payment_received' => 'Payment Received - Thank You',
            'billing.payment_failed' => 'Payment Failed - Action Required',
            'billing.server_renewal_notice' => 'Server Renewal Notice - Action Required',
        ];

        return $subjects[$templateKey] ?? 'Notification';
    }
}
