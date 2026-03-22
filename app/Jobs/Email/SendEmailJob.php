<?php

namespace Everest\Jobs\Email;

use Everest\Jobs\Job;
use Everest\Models\EmailDelivery;
use Everest\Models\EmailQuota;
use Everest\Models\DeferredEmail;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\EmailManager;
use Everest\Services\Email\EmailTypeRegistry;
use Everest\Services\Email\EmailDeliveryTracker;
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
     * Note: correlationId should ALWAYS be provided by EmailNotificationListener.
     */
    public function __construct(
        public string $templateKey,
        public string $recipient,
        public array $data,
        public ?int $userId = null,
        public ?string $correlationId = null
    ) {
        // Ensure we have correlation_id (fallback only for direct job dispatch)
        $this->correlationId = $correlationId ?? \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Execute the job.
     */
    public function handle(EmailManager $emailManager, EmailDeliveryTracker $tracker): void
    {
        if (!EmailManager::isDeliveryEnabled()) {
            Log::info('SendEmailJob: Email delivery disabled, skipping dispatch', [
                'template_key' => $this->templateKey,
                'recipient' => $this->recipient,
                'correlation_id' => $this->correlationId,
            ]);
            return;
        }

        Log::info('SendEmailJob: Starting', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'correlation_id' => $this->correlationId,
            'attempt' => $this->attempts(),
        ]);

        $provider = EmailManager::getTransport();

        // Hard block invalid or blacklisted recipients before any processing
        if (EmailManager::isBlockedRecipient($this->recipient)) {
            $delivery = $tracker->startDelivery(
                correlationId: $this->correlationId,
                recipient: $this->recipient,
                subject: $this->getSubjectForTemplate($this->templateKey),
                templateKey: $this->templateKey,
                userId: $this->userId,
                tags: $this->buildTags(),
                provider: $provider
            );

            $tracker->markSkipped($delivery, 'Blocked recipient email');

            Log::warning('SendEmailJob: Blocked recipient email, skipping send', [
                'template_key' => $this->templateKey,
                'recipient' => $this->recipient,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        // Get subject for the email
        $subject = $this->getSubjectForTemplate($this->templateKey);

        // Check for existing delivery or create new one
        $delivery = $tracker->findByCorrelationId($this->correlationId);
        
        if (!$delivery) {
            // First attempt - create delivery record
            $delivery = $tracker->startDelivery(
                correlationId: $this->correlationId,
                recipient: $this->recipient,
                subject: $subject,
                templateKey: $this->templateKey,
                userId: $this->userId,
                tags: $this->buildTags(),
                provider: $provider
            );
        }

        // Check if this email type is enabled (template-level)
        if (!EmailNotificationSetting::isTemplateEnabled($this->templateKey)) {
            $reason = "Email type '{$this->templateKey}' is disabled";

            Log::info('SendEmailJob: Email type disabled', [
                'template_key' => $this->templateKey,
                'correlation_id' => $this->correlationId,
                'reason' => $reason,
            ]);
            
            $tracker->markSkipped($delivery, $reason);
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

                $tracker->markDeferred($delivery, $reason, $nextAvailable);

                DeferredEmail::create([
                    'user_id' => $this->userId,
                    'template_key' => $this->templateKey,
                    'recipient' => $this->recipient,
                    'data' => $this->data,
                    'correlation_id' => $this->correlationId,
                    'reason' => $reason,
                    'scheduled_at' => $nextAvailable,
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

            throw new \Exception('Variable validation failed: ' . implode(', ', $errors));
        }

        // Send the email - EmailManager will use tracker to log attempts
        $result = $emailManager->sendFromTemplate(
            templateKey: $this->templateKey,
            recipient: $this->recipient,
            data: $validData,
            correlationId: $this->correlationId,
            userId: $this->userId,
            delivery: $delivery,
            attemptNumber: $this->attempts()
        );

        if (!$result->success) {
            if ($result->retryable === false) {
                Log::warning('SendEmailJob: Non-retryable failure, stopping retries', [
                    'template_key' => $this->templateKey,
                    'recipient' => $this->recipient,
                    'error' => $result->error,
                    'correlation_id' => $this->correlationId,
                ]);

                return;
            }

            throw new \Exception($result->error ?? 'Unknown error');
        }

        Log::info('SendEmailJob: Email sent successfully', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'message_id' => $result->messageId,
            'correlation_id' => $this->correlationId,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendEmailJob: Job failed permanently', [
            'template_key' => $this->templateKey,
            'recipient' => $this->recipient,
            'error' => $exception->getMessage(),
            'correlation_id' => $this->correlationId,
        ]);

        // The final attempt failure has already been logged by EmailManager
        // No additional action needed here
    }

    /**
     * Get subject for template.
     */
    private function getSubjectForTemplate(string $templateKey): string
    {
        $subjects = [
            'auth.account_created' => 'Welcome to ' . config('app.name'),
            'auth.account_locked' => 'Your Account Has Been Locked',
            'auth.account_unsuspended' => 'Your Account Has Been Reactivated',
            'auth.email_verification' => 'Verify Your Email Address',
            'auth.password_reset' => 'Reset Your Password',
            'auth.password_changed' => 'Your Password Has Been Changed',
            'auth.new_login' => 'New Login Detected',
            'auth.2fa_enabled' => 'Two-Factor Authentication Enabled',
            'auth.2fa_disabled' => 'Two-Factor Authentication Disabled',
            'server.created' => 'Your Server Has Been Created',
            'server.suspended' => 'Server Suspended',
            'server.unsuspended' => 'Server Reactivated',
            'billing.payment_received' => 'Payment Received',
            'billing.payment_failed' => 'Payment Failed',
            'billing.server_renewal_notice' => 'Server Renewal Notice',
        ];

        return $subjects[$templateKey] ?? 'Notification from ' . config('app.name');
    }

    /**
     * Build tags for the email.
     */
    private function buildTags(): array
    {
        return [
            [
                'name' => 'template_key',
                'value' => $this->templateKey,
            ],
            [
                'name' => 'correlation_id',
                'value' => $this->correlationId,
            ],
        ];
    }
}
