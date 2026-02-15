<?php

namespace Everest\Services\Email;

use Everest\Models\EmailLog;
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

            // Log successful send
            $this->logEmail($message, $messageId, true);

            Log::info('Email sent successfully via Resend', [
                'message_id' => $messageId,
                'to' => $message->to,
                'subject' => $message->subject,
            ]);

            return EmailResult::success($messageId);
        } catch (ResendException $e) {
            // Log failed send
            $this->logEmail($message, null, false, $e->getMessage());

            Log::error('Failed to send email via Resend', [
                'to' => $message->to,
                'subject' => $message->subject,
                'error' => $e->getMessage(),
            ]);

            return EmailResult::failure($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Log email send attempt.
     */
    private function logEmail(
        EmailMessage $message,
        ?string $messageId,
        bool $success,
        ?string $error = null
    ): void {
        try {
            EmailLog::create([
                'to' => $message->to,
                'subject' => $message->subject,
                'message_id' => $messageId,
                'success' => $success,
                'error' => $error,
                'tags' => $message->tags ? json_encode($message->tags) : null,
            ]);
        } catch (\Exception $e) {
            // Don't fail email sending if logging fails
            Log::error('Failed to log email send', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
