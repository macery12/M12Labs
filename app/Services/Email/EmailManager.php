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
        // Check if Resend is enabled
        if (!$this->isEnabled()) {
            Log::info('Email sending is disabled, skipping', [
                'recipient' => $recipient,
                'template_key' => $templateKey,
                'correlation_id' => $correlationId,
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

        // Convert template key to view path (auth_password_reset -> emails.auth.password-reset)
        // First, split the template key to get category and action
        $parts = explode('_', $templateKey, 2);
        if (count($parts) === 2) {
            // auth_password_reset -> emails.auth.password-reset
            $viewPath = 'emails.' . $parts[0] . '.' . str_replace('_', '-', $parts[1]);
        } else {
            // Fallback for unexpected format
            $viewPath = 'emails.' . str_replace('_', '-', $templateKey);
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

            // Log the failure
            EmailLog::create([
                'to' => $recipient,
                'subject' => $subject,
                'template_key' => $templateKey,
                'correlation_id' => $correlationId,
                'provider' => 'resend',
                'user_id' => $userId,
                'success' => false,
                'status' => 'failed',
                'error' => 'Template rendering failed: ' . $e->getMessage(),
            ]);

            return EmailResult::failure('Failed to render email template: ' . $e->getMessage());
        }

        // Generate text content
        $text = $this->htmlToText($html);

        // Create tags
        // Sanitize tag values: Resend only accepts ASCII letters, numbers, underscores, or dashes
        $sanitizedTemplateKey = str_replace('.', '_', $templateKey);
        
        $tags = [
            [
                'name' => 'template_key',
                'value' => $sanitizedTemplateKey,
            ],
        ];

        if ($correlationId) {
            // Sanitize correlation ID: remove any special characters except allowed ones
            $sanitizedCorrelationId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $correlationId);
            $tags[] = [
                'name' => 'correlation_id',
                'value' => substr($sanitizedCorrelationId, 0, 256), // Resend tag value limit
            ];
        }

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

        // Send via Resend
        $service = new ResendService($apiKey);
        $result = $service->send($message);

        // Log the attempt
        EmailLog::create([
            'to' => $recipient,
            'subject' => $subject,
            'template_key' => $templateKey,
            'correlation_id' => $correlationId,
            'message_id' => $result->messageId,
            'provider' => 'resend',
            'user_id' => $userId,
            'success' => $result->success,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->error,
            'tags' => $tags,
        ]);

        return $result;
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
            'auth_account_created' => 'Welcome to Your Account',
            'auth_email_verification' => 'Verify Your Email Address',
            'auth_password_reset' => 'Reset Your Password',
            'auth_password_changed' => 'Your Password Has Been Changed',
            'auth_new_login' => 'New Login Detected',
            'auth_account_locked' => 'Your Account Has Been Suspended',
            'auth_account_unsuspended' => 'Your Account Has Been Restored',
            'auth_2fa_enabled' => 'Two-Factor Authentication Enabled',
            'auth_2fa_disabled' => 'Two-Factor Authentication Disabled',
            'server_created' => 'Your Server Has Been Created',
            'server_suspended' => 'Your Server Has Been Suspended',
            'server_unsuspended' => 'Your Server Has Been Unsuspended',
            'server_expiring_soon' => 'Your Server Is Expiring Soon',
            'billing_payment_received' => 'Payment Received - Thank You',
            'billing_payment_failed' => 'Payment Failed - Action Required',
            'billing_server_renewal_notice' => 'Server Renewal Notice - Action Required',
        ];

        return $subjects[$templateKey] ?? 'Notification';
    }
}
