<?php

namespace Everest\Services\Email;

class EmailMessage
{
    private const TAG_ALLOWED_PATTERN = '/[^A-Za-z0-9_-]/';
    private const MAX_TAG_LENGTH = 256;

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
     * Sanitize a tag value to meet Resend API requirements.
     * Tags must match ^[A-Za-z0-9_-]+$ (only ASCII letters, numbers, underscores, or dashes).
     * 
     * @param string $value The tag value to sanitize
     * @return string The sanitized value with invalid characters replaced by underscores
     */
    private function sanitizeTag(string $value): string
    {
        // Replace any character that's not A-Z, a-z, 0-9, underscore, or dash with underscore
        return preg_replace(self::TAG_ALLOWED_PATTERN, '_', $value);
    }

    /**
     * Convert the email message to an array for the Resend API.
     * 
     * Tags are automatically sanitized to prevent ASCII errors:
     * - Only allows: A-Z, a-z, 0-9, underscore, hyphen
     * - Converts dots and other characters to underscores
     * - This ensures template keys with dots (e.g., 'auth.password_reset') 
     *   are safe when used as tag values (becomes 'auth_password_reset')
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
            $cleanTags = [];

            foreach ($this->tags as $tag) {
                // Sanitize both tag name and value using the dedicated sanitization method
                // This ensures Resend API requirements are met: ^[A-Za-z0-9_-]+$
                $name = $this->sanitizeTag((string) ($tag['name'] ?? ''));
                $value = $this->sanitizeTag((string) ($tag['value'] ?? ''));

                // Trim to max length and skip empty tags
                $name = substr($name, 0, self::MAX_TAG_LENGTH);
                $value = substr($value, 0, self::MAX_TAG_LENGTH);

                if ($name !== '' && $value !== '') {
                    $cleanTags[] = [
                        'name' => $name,
                        'value' => $value,
                    ];
                }
            }

            if (!empty($cleanTags)) {
                $data['tags'] = $cleanTags;
            }
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
