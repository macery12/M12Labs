<?php

namespace Everest\Services\Email;

use Everest\Models\Setting;
use Everest\Models\EmailLog;
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
     */
    public function sendFromTemplate(
        string $templateKey,
        string $recipient,
        array $data,
        ?string $correlationId = null,
        ?int $userId = null
    ): EmailResult {
        $startTime = microtime(true);
        
        // Ensure we have a correlation_id for tracking
        $correlationId = $correlationId ?? \Illuminate\Support\Str::uuid()->toString();
        
        // Check if Resend is enabled
        if (!$this->isEnabled()) {
            Log::info('Email sending is disabled, skipping', [
                'recipient' => $recipient,
                'template_key' => $templateKey,
                'correlation_id' => $correlationId,
            ]);

            // Update the log entry (should already exist from SendEmailJob)
            EmailLog::where('correlation_id', $correlationId)->update([
                'status' => 'skipped',
                'error' => 'Email sending is disabled',
            ]);

            return EmailResult::success('disabled');
        }

        // Get API key from settings
        $apiKey = Setting::get('settings::modules:email:resend:api_key');
        if (empty($apiKey)) {
            Log::error('Resend API key not configured');
            
            EmailLog::where('correlation_id', $correlationId)->update([
                'status' => 'failed',
                'error' => 'Resend API key not configured',
            ]);
            
            return EmailResult::failure('Resend API key not configured. Please configure the API key in Admin → Email settings.');
        }

        // Get from settings
        $from = Setting::get('settings::modules:email:resend:from_email');
        $fromName = Setting::get('settings::modules:email:resend:from_name');
        $replyTo = Setting::get('settings::modules:email:resend:reply_to');

        // Validate required from_email is configured
        if (empty($from)) {
            Log::error('Resend from_email not configured');
            
            EmailLog::where('correlation_id', $correlationId)->update([
                'status' => 'failed',
                'error' => 'From email address not configured',
            ]);
            
            return EmailResult::failure('From email address not configured. Please set the "From Email" in Admin → Email settings.');
        }

        // Validate from_email format
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            Log::error('Resend from_email has invalid format', ['from' => $from]);
            
            EmailLog::where('correlation_id', $correlationId)->update([
                'status' => 'failed',
                'error' => 'From email address has invalid format: ' . $from,
            ]);
            
            return EmailResult::failure('From email address has invalid format: ' . $from . '. Please check the "From Email" in Admin → Email settings.');
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
            Log::error('Failed to render email template', [
                'template_key' => $templateKey,
                'view_path' => $viewPath,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            EmailLog::where('correlation_id', $correlationId)->update([
                'status' => 'failed',
                'error' => 'Failed to render email template: ' . $e->getMessage(),
            ]);

            return EmailResult::failure('Failed to render email template: ' . $e->getMessage());
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

        // Get sanitized tags and message data for logging
        $messageArray = $message->toArray();
        $sanitizedTags = $messageArray['tags'] ?? [];

        // Update the log with rendered content and sanitized tags before sending
        EmailLog::where('correlation_id', $correlationId)->update([
            'rendered_subject' => $subject,
            'rendered_html' => $html,
            'rendered_text' => $text,
            'tags' => $sanitizedTags,
            'template_variables' => $data,
        ]);

        // Send via Resend
        $service = new ResendService($apiKey);
        
        try {
            $result = $service->send($message);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Update the log with the final result
            // Use correlation_id to find and update the existing log entry
            EmailLog::where('correlation_id', $correlationId)->update([
                'message_id' => $result->messageId,
                'success' => $result->success,
                'status' => $result->success ? 'sent' : 'failed',
                'error' => $result->error,
                'status_code' => $result->statusCode,
                'duration_ms' => $durationMs,
            ]);

            return $result;
        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            
            // Update log with error
            EmailLog::where('correlation_id', $correlationId)->update([
                'success' => false,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            throw $e;
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
