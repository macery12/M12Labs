<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Tests\TestCase;

class EmailSettingsReaderTest extends TestCase
{
    public function testDeliveryEnabledAcceptsTheStoredSpellingsOfTrue(): void
    {
        foreach (['true', '1', 'on', true] as $value) {
            $this->assertTrue((new FakeEmailSettingsReader(['enabled' => $value]))->deliveryEnabled());
        }

        $this->assertFalse((new FakeEmailSettingsReader(['enabled' => 'false']))->deliveryEnabled());
        $this->assertFalse((new FakeEmailSettingsReader([]))->deliveryEnabled());
    }

    public function testPrimaryDefaultsToSmtpAndIgnoresUnknownProviders(): void
    {
        $this->assertSame('smtp', (new FakeEmailSettingsReader([]))->primary());
        $this->assertSame('resend', (new FakeEmailSettingsReader(['primary' => 'Resend']))->primary());
        $this->assertSame('smtp', (new FakeEmailSettingsReader(['primary' => 'mailgun']))->primary());
    }

    /**
     * A backup equal to the primary would only retry the same failure.
     */
    public function testBackupIsNullWhenNoneOrTheSameAsThePrimary(): void
    {
        $this->assertNull((new FakeEmailSettingsReader([]))->backup());
        $this->assertNull((new FakeEmailSettingsReader(['backup' => 'none']))->backup());
        $this->assertNull((new FakeEmailSettingsReader(['primary' => 'smtp', 'backup' => 'smtp']))->backup());
        $this->assertSame('resend', (new FakeEmailSettingsReader(['primary' => 'smtp', 'backup' => 'resend']))->backup());
    }

    public function testReplyToFallsBackToTheFromAddress(): void
    {
        $this->assertSame('panel@m12labs.test-suite.net', (new FakeEmailSettingsReader(['from_email' => 'panel@m12labs.test-suite.net']))->replyTo());
        $this->assertSame('help@m12labs.test-suite.net', (new FakeEmailSettingsReader([
            'from_email' => 'panel@m12labs.test-suite.net',
            'reply_to' => 'help@m12labs.test-suite.net',
        ]))->replyTo());
    }

    public function testLogRetentionDefaultsToThirtyDays(): void
    {
        $this->assertSame(30, (new FakeEmailSettingsReader([]))->logRetentionDays());
        $this->assertSame(30, (new FakeEmailSettingsReader(['log_retention_days' => '0']))->logRetentionDays());
        $this->assertSame(7, (new FakeEmailSettingsReader(['log_retention_days' => '7']))->logRetentionDays());
    }

    public function testTheAdminPayloadCarriesOneSenderAndNoSecrets(): void
    {
        $settings = (new FakeEmailSettingsReader([
            'enabled' => 'true',
            'primary' => 'resend',
            'backup' => 'smtp',
            'from_email' => 'panel@m12labs.test-suite.net',
            'from_name' => 'Panel',
            'resend:api_key' => 're_secret',
            'smtp:host' => 'smtp.m12labs.test-suite.net',
            'smtp:password' => 'hunter2',
        ]))->adminSettings();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('resend', $settings['primary']);
        $this->assertSame('smtp', $settings['backup']);
        $this->assertSame('resend', $settings['transport']);
        $this->assertSame('panel@m12labs.test-suite.net', $settings['from_email']);
        $this->assertSame('panel@m12labs.test-suite.net', $settings['smtp']['from_email']);
        $this->assertSame('panel@m12labs.test-suite.net', $settings['resend']['from_email']);
        $this->assertTrue($settings['resend']['api_key']);
        $this->assertTrue($settings['smtp']['password_set']);
        $this->assertStringNotContainsString('re_secret', json_encode($settings));
        $this->assertStringNotContainsString('hunter2', json_encode($settings));
        $this->assertArrayNotHasKey('resend_plan', $settings);
    }
}
