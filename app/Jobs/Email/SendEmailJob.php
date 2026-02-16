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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendEmailJob extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DUPLICATE_DISPATCH_TTL_SECONDS = 45;

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
        $logTemplateKey = $this->templateKeyForLog();
        $correlationId = $this->correlationId ?? \Illuminate\Support\Str::uuid()->toString();
        $dedupeKey = $this->dispatchDedupeKey($correlationId, $logTemplateKey);

        Log::info('SendEmailJob: Starting', [
            'template_key' => $logTemplateKey,
            'recipient' => $this->recipient,
            'correlation_id' => $correlationId,
        ]);

        if (!Cache::add($dedupeKey, true, now()->addSeconds(self::DUPLICATE_DISPATCH_TTL_SECONDS))) {
            Log::warning('SendEmailJob: Duplicate dispatch suppressed', [
                'template_key' => $logTemplateKey,
                'recipient' => $this->recipient,
                'correlation_id' => $correlationId,
            ]);

            return;
        }

        // Check if this email type is enabled
        if (!EmailNotificationSetting::isEnabled($logTemplateKey)) {
            Log::info('SendEmailJob: Email type disabled', [
                'template_key' => $logTemplateKey,
                'correlation_id' => $correlationId,
            ]);
            return;
        }

        // Check rate limiting (if user ID provided and not exempt)
        if ($this->userId && !EmailNotificationSetting::isRateLimitExempt($logTemplateKey)) {
            $quota = EmailQuota::getOrCreateForUser($this->userId);
            
            if (!$quota->reserveQuota(1)) {
                // Quota exceeded - defer the email
                $nextAvailable = $quota->getNextAvailableTime();
                $reason = $quota->daily_limit && $quota->daily_sent >= $quota->daily_limit
                    ? 'daily_limit'
                    : 'monthly_limit';

                Log::info('SendEmailJob: Quota exceeded, deferring', [
                    'template_key' => $logTemplateKey,
                    'user_id' => $this->userId,
                    'reason' => $reason,
                    'scheduled_at' => $nextAvailable,
                    'correlation_id' => $correlationId,
                ]);

                DeferredEmail::create([
                    'user_id' => $this->userId,
                    'template_key' => $logTemplateKey,
                    'recipient' => $this->recipient,
                    'data' => $this->data,
                    'correlation_id' => $correlationId,
                    'reason' => $reason,
                    'scheduled_at' => $nextAvailable,
                ]);

                return;
            }
        }

        // Validate variables
        [$validData, $errors] = EmailTypeRegistry::validateVariables($logTemplateKey, $this->data);
        
        if (!empty($errors)) {
            Log::error('SendEmailJob: Variable validation failed', [
                'template_key' => $logTemplateKey,
                'errors' => $errors,
                'correlation_id' => $correlationId,
            ]);

            // Log failed attempt
            EmailLog::create([
                'to' => $this->recipient,
                'subject' => 'Email validation failed',
                'template_key' => $logTemplateKey,
                'correlation_id' => $correlationId,
                'provider' => 'resend',
                'user_id' => $this->userId,
                'success' => false,
                'status' => 'failed',
                'error' => 'Variable validation failed: ' . implode(', ', $errors),
            ]);

            throw new \Exception('Variable validation failed: ' . implode(', ', $errors));
        }

        // Send the email
        try {
            $result = $emailManager->sendFromTemplate(
                $logTemplateKey,
                $this->recipient,
                $validData,
                $correlationId,
                $this->userId
            );

            if (!$result->success) {
                throw new \Exception($result->error ?? 'Unknown error');
            }

            Log::info('SendEmailJob: Email sent successfully', [
                'template_key' => $logTemplateKey,
                'recipient' => $this->recipient,
                'message_id' => $result->messageId,
                'correlation_id' => $correlationId,
            ]);
        } catch (\Exception $e) {
            Log::error('SendEmailJob: Failed to send email', [
                'template_key' => $logTemplateKey,
                'recipient' => $this->recipient,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $logTemplateKey = $this->templateKeyForLog();
        $correlationId = $this->correlationId ?? \Illuminate\Support\Str::uuid()->toString();

        Log::error('SendEmailJob: Job failed permanently', [
            'template_key' => $logTemplateKey,
            'recipient' => $this->recipient,
            'error' => $exception->getMessage(),
            'correlation_id' => $correlationId,
        ]);

        // Log the failed attempt
        EmailLog::create([
            'to' => $this->recipient,
            'subject' => 'Email job failed',
            'template_key' => $logTemplateKey,
            'correlation_id' => $correlationId,
            'provider' => 'resend',
            'user_id' => $this->userId,
            'success' => false,
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }

    private function templateKeyForLog(): string
    {
        return $this->templateKey;
    }

    private function dispatchDedupeKey(string $correlationId, string $logTemplateKey): string
    {
        return "email_dispatch:{$correlationId}:{$logTemplateKey}:{$this->recipient}";
    }
}
