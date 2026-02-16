<?php

namespace Everest\Tests\Unit\Models;

use Everest\Models\EmailNotificationSetting;
use Everest\Tests\TestCase;

class EmailNotificationSettingTest extends TestCase
{
    public function testNormalizeTemplateKeyConvertsDotsToUnderscores(): void
    {
        $this->assertSame('auth_password_reset', EmailNotificationSetting::normalizeTemplateKey('auth.password_reset'));
        $this->assertSame('billing_server_renewal_notice', EmailNotificationSetting::normalizeTemplateKey('billing.server_renewal_notice'));
    }

    public function testLegacyDotTemplateConversionFromNormalizedKey(): void
    {
        $reflection = new \ReflectionClass(EmailNotificationSetting::class);
        $method = $reflection->getMethod('toLegacyDotTemplateKey');
        $method->setAccessible(true);

        $this->assertSame('auth.password_reset', $method->invoke(null, 'auth_password_reset'));
        $this->assertSame('billing.server_renewal_notice', $method->invoke(null, 'billing_server_renewal_notice'));
    }
}
