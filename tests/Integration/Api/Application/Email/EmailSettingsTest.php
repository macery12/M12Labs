<?php

namespace Everest\Tests\Integration\Api\Application\Email;

use Everest\Models\Setting;
use Everest\Repositories\Eloquent\SettingsRepository;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class EmailSettingsTest extends ApplicationApiIntegrationTestCase
{
    protected function tearDown(): void
    {
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    /**
     * The overview says plainly when password resets cannot go out.
     */
    public function testTheSettingsSayWhatStopsEachProvider(): void
    {
        Setting::set('settings::modules:email:primary', 'resend');
        Setting::set('settings::modules:email:from_email', 'panel@m12labs.test-suite.net');

        $this->getJson('/api/application/email/settings')
            ->assertOk()
            ->assertJsonPath('primary', 'resend')
            ->assertJsonPath('backup', 'none')
            ->assertJsonPath('status.ready', false)
            ->assertJsonPath('status.resend', 'No Resend API key is set. Add one in Admin → Email → Resend.')
            ->assertJsonMissingPath('transport');
    }

    public function testProvidersAndSenderSaveTogether(): void
    {
        $this->putJson('/api/application/email/settings', [
            'primary' => 'smtp',
            'backup' => 'resend',
            'from_email' => 'panel@m12labs.test-suite.net',
            'from_name' => 'Panel',
            'smtp_host' => 'smtp.m12labs.test-suite.net',
            'smtp_port' => '587',
            'log_retention_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('primary', 'smtp')
            ->assertJsonPath('backup', 'resend')
            ->assertJsonPath('from_name', 'Panel')
            ->assertJsonPath('log_retention_days', 14)
            ->assertJsonPath('status.ready', true);
    }

    public function testTheBackupCannotBeThePrimary(): void
    {
        $this->putJson('/api/application/email/settings', ['primary' => 'smtp', 'backup' => 'smtp'])
            ->assertStatus(422);
    }
}
