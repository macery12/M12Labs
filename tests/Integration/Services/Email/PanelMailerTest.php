<?php

namespace Everest\Tests\Integration\Services\Email;

use Everest\Models\User;
use Everest\Models\Setting;
use Everest\Models\EmailDelivery;
use Everest\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Queue;
use Everest\Mail\TwoFactorEnabledMail;
use Everest\Services\Email\PanelMailer;
use Everest\Jobs\Email\SendPanelMailJob;
use Everest\Models\EmailNotificationSetting;
use Everest\Events\Email\PasswordResetRequested;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Repositories\Eloquent\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class PanelMailerTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const TO = 'person@m12labs.test-suite.net';

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('settings::modules:email:enabled', 'true');
        Setting::set('settings::modules:email:primary', 'resend');
    }

    protected function tearDown(): void
    {
        // The repository's cache is static and outlives the rolled-back rows.
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    public function testAMessageIsLoggedAsQueuedAndDispatched(): void
    {
        $delivery = $this->mailer()->send($this->twoFactorMail(), self::TO, null, 'corr-queued');

        $this->assertNotNull($delivery);
        $this->assertSame(EmailDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('auth.2fa_enabled', $delivery->template_key);
        $this->assertSame('Two-Factor Authentication Enabled', $delivery->subject);
        $this->assertSame('resend', $delivery->provider, 'Until a send says otherwise, the primary.');
        $this->assertSame(self::TO, $delivery->recipient);

        Queue::assertPushed(SendPanelMailJob::class, fn (SendPanelMailJob $job) => $job->deliveryId === $delivery->id
            && $job->mail->hasTo(self::TO));
    }

    /** With mail switched off there would otherwise be a row for every login. */
    public function testNothingIsLoggedWhenDeliveryIsOff(): void
    {
        Setting::set('settings::modules:email:enabled', 'false');

        $this->assertNull($this->mailer()->send($this->twoFactorMail(), self::TO));
        $this->assertSame(0, EmailDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function testABlockedRecipientIsSkipped(): void
    {
        $delivery = $this->mailer()->send($this->twoFactorMail(), 'someone@example.com');

        $this->assertSame(EmailDelivery::STATUS_SKIPPED, $delivery?->status);
        $this->assertSame('Blocked recipient email', $delivery?->last_error);
        Queue::assertNothingPushed();
    }

    public function testATypeSwitchedOffIsSkipped(): void
    {
        EmailNotificationSetting::query()->where('template_key', 'auth.2fa_enabled')->update(['enabled' => false]);

        $delivery = $this->mailer()->send($this->twoFactorMail(), self::TO);

        $this->assertSame(EmailDelivery::STATUS_SKIPPED, $delivery?->status);
        Queue::assertNothingPushed();
    }

    /** Switching off password reset used to break account recovery outright. */
    public function testALockedTypeIgnoresItsToggle(): void
    {
        EmailNotificationSetting::query()->where('template_key', 'auth.password_reset')->update(['enabled' => false]);

        $delivery = $this->mailer()->send(new PasswordResetMail('person', 'https://panel.test/reset', '60 minutes'), self::TO);

        $this->assertSame(EmailDelivery::STATUS_QUEUED, $delivery?->status);
        Queue::assertPushed(SendPanelMailJob::class);
    }

    /**
     * Renewal notices derive their correlation id so a notice already queued
     * or sent is not sent twice, while a failed one can go again on the same row.
     */
    public function testACorrelationIdIsSentOnceButAFailedOneCanBeResent(): void
    {
        $first = $this->mailer()->send($this->twoFactorMail(), self::TO, null, 'corr-renewal');
        $this->assertSame($first?->id, $this->mailer()->send($this->twoFactorMail(), self::TO, null, 'corr-renewal')?->id);
        Queue::assertPushed(SendPanelMailJob::class, 1);

        $first?->update(['status' => EmailDelivery::STATUS_FAILED, 'last_error' => 'boom']);

        $again = $this->mailer()->send($this->twoFactorMail(), self::TO, null, 'corr-renewal');
        $this->assertSame($first?->id, $again?->id);
        $this->assertSame(EmailDelivery::STATUS_QUEUED, $again?->status);
        $this->assertNull($again?->last_error);
        Queue::assertPushed(SendPanelMailJob::class, 2);
    }

    /** Billing events default their correlation id to ''. */
    public function testAnEmptyCorrelationIdGetsItsOwn(): void
    {
        $a = $this->mailer()->send($this->twoFactorMail(), self::TO, null, '');
        $b = $this->mailer()->send($this->twoFactorMail(), self::TO, null, '');

        $this->assertNotSame($a?->correlation_id, $b?->correlation_id);
        $this->assertNotSame('', $a?->correlation_id);
    }

    public function testTheListenerTurnsTheEventIntoItsMailable(): void
    {
        $user = User::factory()->create(['email' => self::TO]);

        event(new PasswordResetRequested($user, 'https://panel.test/reset/xyz', 'corr-listener'));

        Queue::assertPushed(SendPanelMailJob::class, fn (SendPanelMailJob $job) => $job->mail instanceof PasswordResetMail
            && $job->mail->resetUrl === 'https://panel.test/reset/xyz'
            && EmailDelivery::query()->find($job->deliveryId)?->correlation_id === 'corr-listener'
            && EmailDelivery::query()->find($job->deliveryId)?->user_id === $user->id);
    }

    private function mailer(): PanelMailer
    {
        return app(PanelMailer::class);
    }

    private function twoFactorMail(): TwoFactorEnabledMail
    {
        return new TwoFactorEnabledMail('person', 'September 29, 2026 9:00 AM');
    }
}
