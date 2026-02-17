<?php

namespace Everest\Services\Email\Emails;

abstract class BaseEmail
{
    /**
     * Get the email subject.
     */
    abstract public function subject(): string;

    /**
     * Get the Blade view name for the email.
     */
    abstract public function view(): string;

    /**
     * Get the data to pass to the Blade view.
     */
    abstract public function data(): array;

    /**
     * Get optional tags for the email.
     */
    public function tags(): ?array
    {
        return null;
    }

    /**
     * Get optional plain text version.
     * If null, EmailManager will auto-generate from HTML.
     */
    public function text(): ?string
    {
        return null;
    }
}
