<?php

namespace Everest\Tests\Integration\Services\Email;

use Everest\Models\EmailDelivery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Everest\Mail\TwoFactorEnabledMail;
use Everest\Jobs\Email\SendPanelMailJob;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Services\Email\EmailDeliveryTracker;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Email\PanelMailerConfigurator;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Everest\Exceptions\Service\Email\EmailNotConfiguredException;

class SendPanelMailJobTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private string $storage;

    public function setUp(): void
    {
        parent::setUp();

        // Templates compile into storage/framework/twig; keep that out of the
        // live checkout's cache.
        $this->storage = sys_get_temp_dir() . '/panel-mail-job-' . bin2hex(random_bytes(4));
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function testASendIsRecordedWithItsProviderAndMessageId(): void
    {
        $this->panelMailerUses(['smtp'], new RecordingTransport());
        $job = $this->job();

        $this->handle($job);

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame(EmailDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('smtp', $delivery->provider);
        $this->assertNotNull($delivery->provider_message_id);
        $this->assertNotNull($delivery->sent_at);

        $attempt = $delivery->deliveryAttempts()->sole();
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertTrue($attempt->success);
        $this->assertSame('smtp', $attempt->provider);
    }

    /**
     * The failover transport does not say which member sent; Resend's id
     * header on the message is what gives it away.
     */
    public function testTheResendIdMarksAFailoverSendAsResend(): void
    {
        $this->panelMailerUses(['smtp', 'resend'], new RecordingTransport(resendId: 're_123'));
        $job = $this->job();

        $this->handle($job);

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame('resend', $delivery->provider);
        $this->assertSame('re_123', $delivery->provider_message_id);
        $this->assertSame('resend', $delivery->deliveryAttempts()->sole()->provider);
    }

    public function testATransientFailureIsRethrownForTheBackoff(): void
    {
        $this->panelMailerUses(['smtp'], new RecordingTransport(throw: new TransportException('Connection could not be established with host "smtp.test:587".')));
        $job = $this->job();

        try {
            $this->handle($job);
            $this->fail('A transient failure has to reach the queue to be retried.');
        } catch (TransportException) {
        }

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame(EmailDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('Connection could not be established', (string) $delivery->last_error);
        $this->assertFalse($delivery->deliveryAttempts()->sole()->success);
    }

    /** A wrong password fails the same way every time. */
    public function testAnAuthFailureIsNotRetried(): void
    {
        $this->panelMailerUses(['smtp'], new RecordingTransport(throw: new TransportException('Failed to authenticate on SMTP server with username "mailer".')));
        $job = $this->job();

        $this->handle($job);

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame(EmailDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(535, $delivery->last_status_code);
    }

    public function testMissingSettingsFailWithoutRetrying(): void
    {
        $this->mock(PanelMailerConfigurator::class)
            ->shouldReceive('configure')
            ->andThrow(new EmailNotConfiguredException('No Resend API key is set.', 'resend'));
        $job = $this->job();

        $this->handle($job);

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame(EmailDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('No Resend API key is set.', $delivery->last_error);
    }

    public function testABrokenTemplateFailsWithoutRetrying(): void
    {
        $transport = new RecordingTransport();
        $this->panelMailerUses(['smtp'], $transport);

        $mail = new class ('person', 'now') extends TwoFactorEnabledMail {
            public function renderBody(): string
            {
                throw new \RuntimeException('Unknown "nope" filter.');
            }
        };
        $job = $this->job($mail);

        $this->handle($job);

        $this->assertSame(0, $transport->sent);
        $this->assertStringContainsString('could not be rendered', (string) EmailDelivery::query()->findOrFail($job->deliveryId)->last_error);
    }

    /** A resent delivery keeps its earlier attempts. */
    public function testAttemptsAreNumberedFromTheLog(): void
    {
        $this->panelMailerUses(['smtp'], new RecordingTransport(throw: new TransportException('Connection refused')));
        $job = $this->job();

        foreach ([1, 2] as $ignored) {
            try {
                $this->handle($job);
            } catch (TransportException) {
            }
        }

        $delivery = EmailDelivery::query()->findOrFail($job->deliveryId);
        $this->assertSame([1, 2], $delivery->deliveryAttempts()->pluck('attempt_number')->all());
        $this->assertSame(2, $delivery->attempts);
    }

    public function testASentDeliveryIsNotSentAgain(): void
    {
        $transport = new RecordingTransport();
        $this->panelMailerUses(['smtp'], $transport);
        $job = $this->job();
        EmailDelivery::query()->whereKey($job->deliveryId)->update(['status' => EmailDelivery::STATUS_SENT]);

        $this->handle($job);

        $this->assertSame(0, $transport->sent);
    }

    /**
     * One circuit breaker for all mail: when the provider is down the backlog
     * parks instead of each message paying the whole backoff.
     */
    public function testTheCircuitBreakerIsSharedByAllMail(): void
    {
        $middleware = $this->job()->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[0]);
        $this->assertSame('email-provider', (fn () => $this->key)->call($middleware[0]));
    }

    /**
     * @param list<string> $providers
     */
    private function panelMailerUses(array $providers, RecordingTransport $transport): void
    {
        Mail::extend('recording', fn () => $transport);
        // What the real configurator would have written.
        config([
            'mail.mailers.panel' => ['transport' => 'recording'],
            'mail.from' => ['address' => 'panel@m12labs.test-suite.net', 'name' => 'Panel'],
        ]);
        Mail::purge('panel');

        $this->mock(PanelMailerConfigurator::class)->shouldReceive('configure')->andReturn($providers);
    }

    private function job(?TwoFactorEnabledMail $mail = null): SendPanelMailJob
    {
        $mail ??= new TwoFactorEnabledMail('person', 'September 29, 2026 9:00 AM');
        $mail->to('person@m12labs.test-suite.net');

        $delivery = app(EmailDeliveryTracker::class)->record(
            mail: $mail,
            recipient: 'person@m12labs.test-suite.net',
            correlationId: (string) \Illuminate\Support\Str::uuid(),
            userId: null,
            provider: 'smtp',
        );

        return new SendPanelMailJob($mail, $delivery->id);
    }

    private function handle(SendPanelMailJob $job): void
    {
        $job->handle(app(PanelMailerConfigurator::class), app(EmailDeliveryTracker::class), app(EmailSettingsReader::class));
    }
}

class RecordingTransport extends AbstractTransport
{
    public int $sent = 0;

    public function __construct(private ?string $resendId = null, private ?\Throwable $throw = null)
    {
        parent::__construct();
    }

    protected function doSend(SymfonySentMessage $message): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        ++$this->sent;

        if ($this->resendId !== null) {
            $original = $message->getOriginalMessage();

            if ($original instanceof \Symfony\Component\Mime\Message) {
                $original->getHeaders()->addTextHeader('X-Resend-Email-ID', $this->resendId);
            }
        }
    }

    public function __toString(): string
    {
        return 'recording';
    }
}
