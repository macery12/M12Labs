<?php

namespace Everest\Services\Email;

use Everest\Models\Setting;
use Everest\Models\EmailLog;
use Everest\Models\EmailDelivery;
use Everest\Services\Email\Emails\BaseEmail;
use Everest\Services\Email\Emails\CustomMessageEmail;
use Everest\Exceptions\Service\Email\ResendException;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;

class EmailManager
{
    /**
     * Send an email using a BaseEmail type.
     *
     * @throws ResendException
     */
    public function send(BaseEmail $email, string $recipient): EmailResult
    {
        // Check if Resend is enabled
        if (!$this->isEnabled()) {
            Log::info('Email sending is disabled, skipping', [
                'recipient' => $recipient,
                'subject' => $email->subject(),
            ]);

            return EmailResult::success('disabled');
        }

        // Get API key from settings
        $apiKey = Setting::get('settings::modules:email:resend:api_key');
        if (empty($apiKey)) {
            Log::error('Resend API key not configured');
            return EmailResult::failure('Resend API key not configured. Please configure the API key in Admin → Email settings.');
        }

        // Get from settings
        $from = Setting::get('settings::modules:email:resend:from_email');
        $fromName = Setting::get('settings::modules:email:resend:from_name');
        $replyTo = Setting::get('settings::modules:email:resend:reply_to');

        // Validate required from_email is configured
        if (empty($from)) {
            Log::error('Resend from_email not configured');
            return EmailResult::failure('From email address not configured. Please set the "From Email" in Admin → Email settings.');
        }

        // Validate from_email format
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            Log::error('Resend from_email has invalid format', ['from' => $from]);
            return EmailResult::failure('From email address has invalid format: ' . $from . '. Please check the "From Email" in Admin → Email settings.');
        }

        // Render HTML content
        $html = $this->renderHtml($email);
        
        // Get or generate text content
        $text = $email->text() ?? $this->htmlToText($html);

        // Create email message
        $message = new EmailMessage(
            to: $recipient,
            subject: $email->subject(),
            html: $html,
            text: $text,
            tags: $email->tags(),
            from: $from,
            fromName: $fromName,
            replyTo: $replyTo
        );

        // Send via Resend
        $service = new ResendService($apiKey);
        return $service->send($message);
    }

    /**
     * Send a custom email with raw HTML.
     */
    public function sendCustom(
        string $to,
        string $subject,
        string $html,
        ?string $text = null
    ): EmailResult {
        $customEmail = new CustomMessageEmail(
            subject: $subject,
            html: $html,
            text: $text,
            tags: [
                [
                    'name' => 'category',
                    'value' => 'custom',
                ],
            ]
        );

        return $this->send($customEmail, $to);
    }

    /**
     * Send an email from a template key.
     * This is the main method used by the event-driven email system.
     * 
     * @param EmailDelivery|null $delivery Pre-created delivery record from SendEmailJob
     * @param int $attemptNumber Current attempt number (1-based, from job retry count)
     */
    public function sendFromTemplate(
        string $templateKey,
        string $recipient,
        array $data,
        string $correlationId,
        ?int $userId = null,
        ?EmailDelivery $delivery = null,
        int $attemptNumber = 1
    ): EmailResult {
        $tracker = app(EmailDeliveryTracker::class);

        // If no delivery provided, create one (for direct calls outside job system)
        if (!$delivery) {
            $subject = $this->getSubjectForTemplate($templateKey);
            $tags = [
                ['name' => 'template_key', 'value' => $templateKey],
                ['name' => 'correlation_id', 'value' => $correlationId],
            ];

            $delivery = $tracker->startDelivery(
                correlationId: $correlationId,
                recipient: $recipient,
                subject: $subject,
                templateKey: $templateKey,
                userId: $userId,
                tags: $tags
            );
        }

        // Check if Resend is enabled
        if (!$this->isEnabled()) {
            Log::info('Email sending is disabled, skipping', [
                'recipient' => $recipient,
                'template_key' => $templateKey,
                'correlation_id' => $correlationId,
            ]);

            $tracker->markSkipped($delivery, 'Email sending is disabled');
            return EmailResult::success('disabled');
        }

        // Get API key from settings
        $apiKey = Setting::get('settings::modules:email:resend:api_key');
        if (empty($apiKey)) {
            $error = 'Resend API key not configured. Please configure the API key in Admin → Email settings.';
            Log::error($error);
            
            $attempt = $tracker->startAttempt($delivery, $attemptNumber);
            $tracker->finishAttemptFailure($attempt, $error, 0);
            
            return EmailResult::failure($error);
        }

        // Get from settings
        $from = Setting::get('settings::modules:email:resend:from_email');
        $fromName = Setting::get('settings::modules:email:resend:from_name');
        $replyTo = Setting::get('settings::modules:email:resend:reply_to');

        // Validate required from_email is configured
        if (empty($from)) {
            $error = 'From email address not configured. Please set the "From Email" in Admin → Email settings.';
            Log::error($error);
            
            $attempt = $tracker->startAttempt($delivery, $attemptNumber);
            $tracker->finishAttemptFailure($attempt, $error, 0);
            
            return EmailResult::failure($error);
        }

        // Validate from_email format
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $error = 'From email address has invalid format: ' . $from . '. Please check the "From Email" in Admin → Email settings.';
            Log::error($error);
            
            $attempt = $tracker->startAttempt($delivery, $attemptNumber);
            $tracker->finishAttemptFailure($attempt, $error, 0);
            
            return EmailResult::failure($error);
        }

        // Convert template key to view path (auth.password_reset -> emails.auth.password-reset)
        // Split on first dot to get category and action
        $parts = explode('.', $templateKey, 2);
        if (count($parts) === 2) {
            // auth.password_reset -> emails.auth.password-reset
            $viewPath = 'emails.' . $parts[0] . '.' . str_replace('_', '-', $parts[1]);
        } else {
            // Fallback for unexpected format
            $viewPath = 'emails.' . $templateKey;
        }

        // Get subject from template key
        $subject = $this->getSubjectForTemplate($templateKey);

        // Render HTML content from template
        try {
            $html = View::make($viewPath, $data)->render();
        } catch (\Exception $e) {
            $error = 'Failed to render email template: ' . $e->getMessage();
            Log::error($error, [
                'template_key' => $templateKey,
                'view_path' => $viewPath,
                'correlation_id' => $correlationId,
            ]);

            $attempt = $tracker->startAttempt($delivery, $attemptNumber);
            $tracker->finishAttemptFailure($attempt, $error, 0, $e);

            return EmailResult::failure($error);
        }

        // Generate text content
        $text = $this->htmlToText($html);

        // Create tags
        $tags = [
            [
                'name' => 'template_key',
                'value' => $templateKey,
            ],
            [
                'name' => 'correlation_id',
                'value' => $correlationId,
            ],
        ];

        // Create email message
        $message = new EmailMessage(
            to: $recipient,
            subject: $subject,
            html: $html,
            text: $text,
            tags: $tags,
            from: $from,
            fromName: $fromName,
            replyTo: $replyTo
        );

        // Start attempt tracking
        $requestPayload = $message->toArray();
        $attempt = $tracker->startAttempt($delivery, $attemptNumber, $requestPayload);

        // Send via Resend
        try {
            $service = new ResendService($apiKey);
            $result = $service->send($message);

            if ($result->success) {
                // Success - update attempt
                $tracker->finishAttemptSuccess(
                    attempt: $attempt,
                    providerMessageId: $result->messageId,
                    statusCode: $result->statusCode,
                    responsePayload: ['id' => $result->messageId]
                );
            } else {
                // Failure - update attempt
                $tracker->finishAttemptFailure(
                    attempt: $attempt,
                    error: $result->error ?? 'Unknown error',
                    statusCode: $result->statusCode
                );
            }

            return $result;
        } catch (\Exception $e) {
            // Exception during send - update attempt
            $tracker->finishAttemptFailure(
                attempt: $attempt,
                error: $e->getMessage(),
                statusCode: method_exists($e, 'getCode') ? $e->getCode() : 0,
                exception: $e
            );

            return EmailResult::failure($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Render HTML from email template.
     */
    private function renderHtml(BaseEmail $email): string
    {
        // CustomMessageEmail uses direct HTML
        if ($email instanceof CustomMessageEmail) {
            return $email->getHtml();
        }

        // Render Blade view
        return View::make($email->view(), $email->data())->render();
    }

    /**
     * Convert HTML to plain text.
     */
    private function htmlToText(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Convert common HTML tags to text equivalents
        $html = str_replace('<br>', "\n", $html);
        $html = str_replace('<br/>', "\n", $html);
        $html = str_replace('<br />', "\n", $html);
        $html = str_replace('</p>', "\n\n", $html);
        $html = str_replace('</div>', "\n", $html);
        $html = str_replace('</h1>', "\n\n", $html);
        $html = str_replace('</h2>', "\n\n", $html);
        $html = str_replace('</h3>', "\n\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces to single space
        $text = preg_replace('/\n\s+\n/', "\n\n", $text); // Clean up blank lines
        $text = trim($text);

        return $text;
    }

    /**
     * Check if Resend email is enabled.
     */
    private function isEnabled(): bool
    {
        return Setting::get('settings::modules:email:resend:enabled', false) === 'true';
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
