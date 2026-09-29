<?php

namespace Everest\Services\Email;

use Illuminate\Mail\SentMessage;
use Symfony\Component\Mime\Message;

/**
 * Which provider took a message, and its id there.
 */
final class DeliveryReceipt
{
    public function __construct(public readonly string $provider, public readonly ?string $messageId)
    {
    }

    /**
     * The failover transport does not say which member sent. Laravel's Resend
     * transport stamps the message with X-Resend-Email-ID once Resend accepts
     * it, and with only SMTP and Resend to choose from, that settles it.
     *
     * @param list<string> $providers what the mailer was going to try, in order
     */
    public static function from(?SentMessage $sent, array $providers): self
    {
        $symfony = $sent?->getSymfonySentMessage();
        $original = $symfony?->getOriginalMessage();
        $resendId = $original instanceof Message
            ? $original->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString()
            : null;

        $provider = count($providers) > 1
            ? ($resendId !== null ? 'resend' : 'smtp')
            : ($providers[0] ?? 'smtp');

        // SMTP: the id the server queued it under, or the Message-ID header.
        return new self($provider, $resendId ?? $symfony?->getMessageId());
    }
}
