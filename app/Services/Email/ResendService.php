<?php

namespace Everest\Services\Email;

use Everest\Exceptions\Service\Email\ResendException;
use Illuminate\Support\Facades\Log;

class ResendService
{
    private ResendHttpClient $client;

    public function __construct(string $apiKey)
    {
        $this->client = new ResendHttpClient($apiKey);
    }

    /**
     * Send an email using Resend API.
     *
     * Logging is handled by higher-level services (EmailManager, SendEmailJob)
     * that have access to full context (user_id, template_key, correlation_id).
     *
     * @throws ResendException
     */
    public function send(EmailMessage $message): EmailResult
    {
        try {
            $payload = $message->toArray();
            $response = $this->client->sendEmail($payload);

            $messageId = $response['id'] ?? null;

            if (!$messageId) {
                Log::error('Resend API response missing message ID', ['response' => $response]);
                return EmailResult::failure('Response missing message ID');
            }

            Log::info('Email sent successfully via Resend', [
                'message_id' => $messageId,
                'to' => $message->to,
                'subject' => $message->subject,
            ]);

            return EmailResult::success($messageId);
        } catch (ResendException $e) {
            Log::error('Failed to send email via Resend', [
                'to' => $message->to,
                'subject' => $message->subject,
                'error' => $e->getMessage(),
            ]);

            return EmailResult::failure($e->getMessage(), $e->getCode());
        }
    }
}
