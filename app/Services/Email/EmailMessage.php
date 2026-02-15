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

        if ($this->from !== null) {
            $data['from'] = $this->fromName 
                ? "{$this->fromName} <{$this->from}>" 
                : $this->from;
        }

        if ($this->replyTo !== null) {
            $data['reply_to'] = $this->replyTo;
        }

        return $data;
    }
}
