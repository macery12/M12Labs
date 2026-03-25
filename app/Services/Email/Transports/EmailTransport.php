<?php

namespace Everest\Services\Email\Transports;

use Everest\Services\Email\EmailMessage;
use Everest\Services\Email\EmailResult;

interface EmailTransport
{
    /**
     * Send the provided email message.
     */
    public function send(EmailMessage $message): EmailResult;

    /**
     * Human-readable provider/transport name (e.g. resend, smtp).
     */
    public function getName(): string;
}
