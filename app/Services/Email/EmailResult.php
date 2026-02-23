<?php

namespace Everest\Services\Email;

class EmailResult
{
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $statusCode = null,
        public ?string $status = null,
        public ?string $reason = null
    ) {
    }

    /**
     * Create a successful result.
     */
    public static function success(string $messageId, ?int $statusCode = null): self
    {
        return new self(
            success: true,
            messageId: $messageId,
            statusCode: $statusCode,
            status: 'sent'
        );
    }

    /**
     * Create a skipped result for invalid or test-domain recipients.
     */
    public static function skipped(string $reason): self
    {
        return new self(
            success: true,
            status: 'skipped',
            reason: $reason
        );
    }

    /**
     * Create a failed result.
     */
    public static function failure(string $error, ?int $statusCode = null): self
    {
        return new self(
            success: false,
            error: $error,
            statusCode: $statusCode,
            status: 'failed'
        );
    }
}
