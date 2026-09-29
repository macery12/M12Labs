<?php

namespace Everest\Tests\Integration\Api\Application\Email;

use Everest\Models\EmailNotificationSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class EmailNotificationSettingsTest extends ApplicationApiIntegrationTestCase
{
    public function testTheListSaysWhichTypesAreLocked(): void
    {
        $response = $this->getJson('/api/application/email/notifications')->assertOk();

        $rows = collect($response->json('categories.auth'))->keyBy('template_key');
        $this->assertTrue($rows['auth.password_reset']['locked']);
        $this->assertTrue($rows['auth.email_verification']['locked']);
        $this->assertFalse($rows['auth.new_login']['locked']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function lockedTypes(): iterable
    {
        yield 'password reset' => ['auth.password_reset'];
        yield 'email verification' => ['auth.email_verification'];
    }

    /** Without these people cannot get back into, or into, their accounts. */
    #[DataProvider('lockedTypes')]
    public function testALockedTypeCannotBeSwitchedOff(string $key): void
    {
        $setting = EmailNotificationSetting::query()->where('template_key', $key)->firstOrFail();

        $this->putJson("/api/application/email/notifications/{$setting->id}", ['enabled' => false])
            ->assertStatus(422);

        $this->assertTrue($setting->refresh()->enabled);
    }

    public function testAnOrdinaryTypeCanBeSwitchedOff(): void
    {
        $setting = EmailNotificationSetting::query()->where('template_key', 'auth.new_login')->firstOrFail();

        $this->putJson("/api/application/email/notifications/{$setting->id}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('setting.enabled', false);

        $this->assertFalse($setting->refresh()->enabled);
    }
}
