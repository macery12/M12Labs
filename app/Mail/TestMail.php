<?php

namespace Everest\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The admin's delivery test and the per-provider connection checks. Sent
 * straight away rather than queued, so the admin sees the provider's answer,
 * and not tracked in the delivery log.
 */
class TestMail extends Mailable
{
    public function __construct(public readonly bool $connectionCheck = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->connectionCheck ? 'Email provider connection check' : 'Email Delivery Test');
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->connectionCheck
            ? '<p>This provider connection check reached the configured sender address successfully.</p>'
            : '<h1>Email Delivery Test</h1><p>This is a real delivery test message from the email system. If you received it, the configured email providers can deliver mail end-to-end.</p>');
    }
}
