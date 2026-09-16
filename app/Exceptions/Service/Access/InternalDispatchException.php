<?php

namespace Everest\Exceptions\Service\Access;

/**
 * An internal sub-request could not be run, or could not be read back.
 *
 * Three distinct refusals rather than one, because callers act on them
 * differently: a deadline is worth retrying with less work, a transaction is a
 * caller bug, and an unreadable response means this endpoint can never be
 * reached this way and the caller should stop asking.
 */
class InternalDispatchException extends \RuntimeException
{
    public const REASON_DEADLINE = 'deadline';
    public const REASON_TRANSACTION = 'transaction';
    public const REASON_UNREADABLE = 'unreadable';
    public const REASON_FORBIDDEN = 'forbidden';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function deadlineElapsed(): self
    {
        return new self(self::REASON_DEADLINE, 'The internal dispatch deadline elapsed.');
    }

    /**
     * The exception handler rolls back to level 0 when it renders, so a failure
     * inside the sub-request would silently discard the caller's own work.
     */
    public static function insideTransaction(): self
    {
        return new self(
            self::REASON_TRANSACTION,
            'An internal request may not be dispatched inside a database transaction.',
        );
    }

    /**
     * `getContent()` returns false on streamed and binary responses; the only
     * way to read one is to send it, which writes into whatever the caller has
     * open — for the agent, a live SSE stream.
     */
    public static function unreadableResponse(): self
    {
        return new self(
            self::REASON_UNREADABLE,
            'That endpoint streams its response and cannot be dispatched internally.',
        );
    }

    public static function notGranted(string $extensionId): self
    {
        return new self(self::REASON_FORBIDDEN, sprintf(
            'Extension "%s" does not hold the "internal_dispatch" privileged capability.',
            $extensionId,
        ));
    }
}
