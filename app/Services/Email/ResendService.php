<?php

namespace Everest\Services\Email;

use Everest\Exceptions\Service\Email\ResendException;

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
     * Returns EmailResult with structured response data.
     * NO logging - that's handled by higher-level services (EmailManager, EmailDeliveryTracker).
     *
     * @throws ResendException
     */
    public function send(EmailMessage $message): EmailResult
    {
        try {
            $payload = $message->toArray();
            $response = $this->client->sendEmail($payload);

            $messageId = $response['body']['id'] ?? null;
            $statusCode = $response['status_code'] ?? null;

            if (!$messageId) {
                return EmailResult::failure('Response missing message ID', $statusCode, true);
            }

            return new EmailResult(
                success: true,
                messageId: $messageId,
                statusCode: $statusCode
            );
        } catch (ResendException $e) {
            $retryable = !($e instanceof \Everest\Exceptions\Service\Email\ResendAuthenticationException
                || $e instanceof \Everest\Exceptions\Service\Email\ResendValidationException);

            return EmailResult::failure($e->getMessage(), $e->getCode(), $retryable);
        }
    }
}
