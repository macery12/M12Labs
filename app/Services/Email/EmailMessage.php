<?php

namespace Everest\Services\Email;

class EmailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public ?string $text = null,
        public ?array $tags = null,
        public ?string $from = null,
        public ?string $fromName = null,
        public ?string $replyTo = null
    ) {
    }

    /**
     * Convert the email message to an array for the Resend API.
     */
    public function toArray(): array
    {
        $data = [
            'to' => [$this->to],
            'subject' => $this->subject,
            'html' => $this->html,
        ];

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->tags !== null && count($this->tags) > 0) {
            $data['tags'] = $this->tags;
        }

        // From field is REQUIRED by Resend API
        // Format: "Name <email@domain.com>" or "email@domain.com"
        if ($this->from !== null && $this->from !== '') {
            $data['from'] = $this->fromName 
                ? "{$this->fromName} <{$this->from}>" 
                : $this->from;
        } else {
            // This should never happen if EmailManager validates correctly
            // But include a fallback to prevent API errors
            throw new \InvalidArgumentException('From email address is required but was not provided');
        }

        if ($this->replyTo !== null && $this->replyTo !== '') {
            $data['reply_to'] = $this->replyTo;
        }

        return $data;
    }
}
