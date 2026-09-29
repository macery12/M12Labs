<?php

namespace Everest\Services\Email;

use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;
use Everest\Exceptions\Service\Email\EmailNotConfiguredException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Why a send failed, and whether trying again could help.
 *
 * Only transient failures retry. A wrong password, an unverified Resend
 * domain or a mailbox the server refuses fails the same way every time, and
 * three attempts spread over twenty minutes only delay the admin seeing it.
 */
final class MailFailure
{
    public const KIND_CONFIG = 'config';
    public const KIND_RENDER = 'render';
    public const KIND_AUTH = 'auth';
    public const KIND_REJECTED = 'rejected';
    public const KIND_TRANSIENT = 'transient';

    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly ?int $code = null,
    ) {
    }

    public function retryable(): bool
    {
        return $this->kind === self::KIND_TRANSIENT;
    }

    public static function render(\Throwable $e): self
    {
        return new self(self::KIND_RENDER, 'The email template could not be rendered: ' . $e->getMessage());
    }

    public static function from(\Throwable $e): self
    {
        if ($e instanceof EmailNotConfiguredException) {
            return new self(self::KIND_CONFIG, $e->getMessage());
        }

        // Laravel's Resend transport wraps the client's error in a
        // TransportException with code 0; the HTTP status is on the cause.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ErrorException) {
                return self::fromResend($cause);
            }

            if ($cause instanceof TransporterException) {
                return new self(self::KIND_TRANSIENT, 'Resend could not be reached: ' . $cause->getMessage());
            }
        }

        $message = $e->getMessage();

        if (str_starts_with($message, 'Failed to authenticate on SMTP server')) {
            return new self(self::KIND_AUTH, $message, 535);
        }

        if ($e instanceof UnexpectedResponseException) {
            $code = (int) $e->getCode();

            // 5xx is the server's final answer; 4xx is "try later".
            return new self($code >= 500 ? self::KIND_REJECTED : self::KIND_TRANSIENT, $message, $code ?: null);
        }

        // Connection errors, timeouts, and failover's "All transports failed."
        // when a backup is set: an outage, most likely, so it is worth the retry.
        return new self(self::KIND_TRANSIENT, $message, $e->getCode() ?: null);
    }

    private static function fromResend(ErrorException $e): self
    {
        $status = self::resendStatus($e);
        $message = 'Resend: ' . $e->getErrorMessage();

        if (stripos($message, 'domain') !== false) {
            $message .= ' Make sure the From address\'s domain is verified at https://resend.com/domains.';
        }

        $kind = match (true) {
            $status === 401, $status === 403 && stripos($message, 'api key') !== false => self::KIND_AUTH,
            $status === 429, $status === null, $status >= 500 => self::KIND_TRANSIENT,
            default => self::KIND_REJECTED,
        };

        return new self($kind, $message, $status);
    }

    private static function resendStatus(ErrorException $e): ?int
    {
        try {
            return $e->getErrorCode();
        } catch (\Throwable) {
            // A body with neither "code" nor "statusCode".
            return null;
        }
    }
}
