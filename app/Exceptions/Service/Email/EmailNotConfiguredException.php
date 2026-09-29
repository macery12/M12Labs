<?php

namespace Everest\Exceptions\Service\Email;

/**
 * The admin Email settings are missing something a provider needs. Retrying
 * cannot fix that, so a send that hits it fails at once instead of burning
 * the job's retries; the message says what to fill in.
 */
class EmailNotConfiguredException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $provider = null)
    {
        parent::__construct($message);
    }
}
