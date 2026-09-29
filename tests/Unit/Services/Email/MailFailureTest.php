<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Tests\TestCase;
use GuzzleHttp\Psr7\Request;
use Resend\Exceptions\ErrorException;
use Everest\Services\Email\MailFailure;
use GuzzleHttp\Exception\ConnectException;
use Resend\Exceptions\TransporterException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Everest\Exceptions\Service\Email\EmailNotConfiguredException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class MailFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{0: \Closure(): \Throwable, 1: string, 2: int|null}>
     */
    public static function failures(): iterable
    {
        $resend = fn (int $status, string $message = 'nope') => fn () => new TransportException(
            'Request to Resend API failed. Reason: ' . $message,
            0,
            new ErrorException(['statusCode' => $status, 'message' => $message, 'name' => 'error']),
        );

        yield 'settings incomplete' => [fn () => new EmailNotConfiguredException('No From address is set.'), MailFailure::KIND_CONFIG, null];
        yield 'resend bad key' => [$resend(401, 'API key is invalid'), MailFailure::KIND_AUTH, 401];
        yield 'resend unverified domain' => [$resend(403, 'The m12labs.test-suite.net domain is not verified.'), MailFailure::KIND_REJECTED, 403];
        yield 'resend validation' => [$resend(422), MailFailure::KIND_REJECTED, 422];
        yield 'resend rate limit' => [$resend(429), MailFailure::KIND_TRANSIENT, 429];
        yield 'resend outage' => [$resend(503), MailFailure::KIND_TRANSIENT, 503];
        yield 'resend unreachable' => [fn () => new TransportException('Request to Resend API failed.', 0, new TransporterException(
            new ConnectException('Connection refused', new Request('POST', 'https://api.resend.com/emails')),
        )), MailFailure::KIND_TRANSIENT, null];
        yield 'smtp bad password' => [fn () => new TransportException('Failed to authenticate on SMTP server with username "mailer" using the following authenticators: "LOGIN".'), MailFailure::KIND_AUTH, 535];
        yield 'smtp mailbox refused' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "550".', 550), MailFailure::KIND_REJECTED, 550];
        yield 'smtp try later' => [fn () => new UnexpectedResponseException('Expected response code "250" but got code "421".', 421), MailFailure::KIND_TRANSIENT, 421];
        yield 'smtp unreachable' => [fn () => new TransportException('Connection could not be established with host "smtp.m12labs.test-suite.net:587".'), MailFailure::KIND_TRANSIENT, null];
        yield 'both providers down' => [fn () => new TransportException('All transports failed.'), MailFailure::KIND_TRANSIENT, null];
    }

    /**
     * @param \Closure(): \Throwable $exception
     */
    #[DataProvider('failures')]
    public function testFailuresAreSortedIntoRetryableAndNot(\Closure $exception, string $kind, ?int $code): void
    {
        $failure = MailFailure::from($exception());

        $this->assertSame($kind, $failure->kind);
        $this->assertSame($code, $failure->code);
        $this->assertSame($kind === MailFailure::KIND_TRANSIENT, $failure->retryable());
    }

    public function testAnUnverifiedDomainSaysWhereToFixIt(): void
    {
        $failure = MailFailure::from(new TransportException('failed', 0, new ErrorException([
            'statusCode' => 403,
            'message' => 'The m12labs.test-suite.net domain is not verified.',
            'name' => 'validation_error',
        ])));

        $this->assertStringContainsString('resend.com/domains', $failure->message);
    }

    public function testATemplateFailureIsNotRetried(): void
    {
        $this->assertFalse(MailFailure::render(new \RuntimeException('Unknown "foo" filter.'))->retryable());
    }
}
