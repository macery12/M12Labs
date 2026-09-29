<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Email\PanelMailerConfigurator;
use Everest\Exceptions\Service\Email\EmailNotConfiguredException;

class PanelMailerConfiguratorTest extends TestCase
{
    private const SMTP = [
        'smtp:host' => 'smtp.m12labs.test-suite.net',
        'smtp:port' => '587',
        'smtp:username' => 'mailer',
        'smtp:password' => 'hunter2',
        'smtp:encryption' => 'tls',
    ];

    private const RESEND = ['resend:api_key' => 're_test_key'];

    private const SENDER = ['from_email' => 'panel@m12labs.test-suite.net', 'from_name' => 'Panel'];

    /**
     * @return iterable<string, array{0: array<string, string>, 1: list<string>, 2: array<string, mixed>}>
     */
    public static function combinations(): iterable
    {
        yield 'smtp alone' => [['primary' => 'smtp'] + self::SMTP + self::RESEND, ['smtp'], ['transport' => 'smtp', 'host' => 'smtp.m12labs.test-suite.net']];
        yield 'resend alone' => [['primary' => 'resend'] + self::SMTP + self::RESEND, ['resend'], ['transport' => 'resend', 'key' => 're_test_key']];
        yield 'smtp then resend' => [['primary' => 'smtp', 'backup' => 'resend'] + self::SMTP + self::RESEND, ['smtp', 'resend'], [
            'transport' => 'failover',
            'mailers' => ['panel_smtp', 'panel_resend'],
        ]];
        yield 'resend then smtp' => [['primary' => 'resend', 'backup' => 'smtp'] + self::SMTP + self::RESEND, ['resend', 'smtp'], [
            'transport' => 'failover',
            'mailers' => ['panel_resend', 'panel_smtp'],
        ]];
        yield 'a backup equal to the primary is no backup' => [['primary' => 'smtp', 'backup' => 'smtp'] + self::SMTP, ['smtp'], ['transport' => 'smtp']];
        yield 'an incomplete backup is left out' => [['primary' => 'smtp', 'backup' => 'resend'] + self::SMTP, ['smtp'], ['transport' => 'smtp']];
    }

    /**
     * @param array<string, string> $settings
     * @param list<string> $order
     * @param array<string, mixed> $panel
     */
    #[DataProvider('combinations')]
    public function testSettingsBecomeThePanelMailer(array $settings, array $order, array $panel): void
    {
        $this->assertSame($order, $this->configurator($settings + self::SENDER)->configure());

        foreach ($panel as $key => $value) {
            $this->assertSame($value, config("mail.mailers.panel.{$key}"), $key);
        }

        $this->assertSame('panel@m12labs.test-suite.net', config('mail.from.address'));
        $this->assertSame('Panel', config('mail.from.name'));
    }

    public function testTlsMapsToStarttlsOrImplicitTlsByPort(): void
    {
        $this->configurator(['primary' => 'smtp'] + self::SMTP + self::SENDER)->configure();
        $this->assertSame('smtp', config('mail.mailers.panel_smtp.scheme'));
        $this->assertTrue(config('mail.mailers.panel_smtp.require_tls'), 'STARTTLS must not silently fall back to plain text.');

        $this->configurator(['primary' => 'smtp', 'smtp:port' => '465'] + self::SMTP + self::SENDER)->configure();
        $this->assertSame('smtps', config('mail.mailers.panel_smtp.scheme'));
        $this->assertFalse(config('mail.mailers.panel_smtp.require_tls'));
    }

    public function testReplyToIsSetOnlyWhenItDiffersFromTheSender(): void
    {
        $this->configurator(['primary' => 'smtp'] + self::SMTP + self::SENDER)->configure();
        $this->assertNull(config('mail.reply_to'));

        $this->configurator(['primary' => 'smtp', 'reply_to' => 'help@m12labs.test-suite.net'] + self::SMTP + self::SENDER)->configure();
        $this->assertSame('help@m12labs.test-suite.net', config('mail.reply_to.address'));
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function incomplete(): iterable
    {
        yield 'no from address' => [['primary' => 'smtp'] + self::SMTP, 'No From address'];
        yield 'bad from address' => [['primary' => 'smtp', 'from_email' => 'not-an-address'] + self::SMTP, 'not a valid email address'];
        yield 'smtp without host' => [['primary' => 'smtp', 'smtp:host' => ''] + self::SMTP + self::SENDER, 'SMTP is missing: host'];
        yield 'smtp username without password' => [['primary' => 'smtp', 'smtp:password' => ''] + self::SMTP + self::SENDER, 'SMTP is missing: password'];
        yield 'resend without key' => [['primary' => 'resend'] + self::SENDER, 'No Resend API key'];
    }

    /**
     * @param array<string, string> $settings
     */
    #[DataProvider('incomplete')]
    public function testAnIncompletePrimaryFailsWithWhatIsMissing(array $settings, string $message): void
    {
        $this->expectException(EmailNotConfiguredException::class);
        $this->expectExceptionMessage($message);

        $this->configurator($settings)->configure();
    }

    public function testAProviderCanBeConfiguredAloneForItsConnectionCheck(): void
    {
        $configurator = $this->configurator(['primary' => 'smtp'] + self::SMTP + self::RESEND + self::SENDER);

        $this->assertSame('panel_resend', $configurator->configureProvider('resend'));

        $this->expectException(EmailNotConfiguredException::class);
        $this->configurator(['primary' => 'resend'] + self::SMTP + self::SENDER)->configureProvider('resend');
    }

    /**
     * The admin screens show what stops each provider, and whether the primary
     * (and so password reset) can send at all.
     */
    public function testStatusReportsEachProviderWithoutConfiguringAnything(): void
    {
        config(['mail.mailers.panel_smtp.host' => null]);

        $status = $this->configurator(['primary' => 'resend', 'backup' => 'smtp'] + self::SMTP + self::SENDER)->status();

        $this->assertFalse($status['ready'], 'Resend is primary and has no key.');
        $this->assertStringContainsString('No Resend API key', (string) $status['resend']);
        $this->assertNull($status['smtp']);
        $this->assertNull(config('mail.mailers.panel_smtp.host'), 'Reading the status must not write mail config.');

        $this->assertTrue($this->configurator(['primary' => 'smtp'] + self::SMTP + self::SENDER)->status()['ready']);
        $this->assertStringContainsString('No From address', (string) $this->configurator(['primary' => 'smtp'] + self::SMTP)->status()['smtp']);
    }

    /**
     * Rebuilding the mailer on every send reopened the SMTP connection and
     * forgot which failover member was down.
     */
    public function testTheMailerIsOnlyRebuiltWhenTheSettingsChange(): void
    {
        $settings = ['primary' => 'smtp'] + self::SMTP + self::SENDER;
        $configurator = $this->configurator($settings, $reader);

        $configurator->configure();
        $first = Mail::mailer('panel');

        $configurator->configure();
        $this->assertSame($first, Mail::mailer('panel'));

        $reader->values['smtp:host'] = 'smtp2.m12labs.test-suite.net';
        $configurator->configure();
        $this->assertNotSame($first, Mail::mailer('panel'));
    }

    /**
     * @param array<string, string> $values
     */
    private function configurator(array $values, ?FakeEmailSettingsReader &$reader = null): PanelMailerConfigurator
    {
        $reader = new FakeEmailSettingsReader($values);

        return new PanelMailerConfigurator($reader);
    }
}
