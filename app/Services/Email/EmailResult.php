<?php

namespace Everest\Services\Email;

class EmailResult
{
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $statusCode = null
    ) {
    }

    /**
     * Create a successful result.
     */
    public static function success(string $messageId): self
    {
        return new self(
            success: true,
            messageId: $messageId
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
            statusCode: $statusCode
        );
    }
}
