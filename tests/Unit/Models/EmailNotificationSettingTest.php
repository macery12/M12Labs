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

    public function testNormalizeTemplateKeyKeepsUnderscoreFormatAsIs(): void
    {
        $this->assertSame('auth_password_reset', EmailNotificationSetting::normalizeTemplateKey('auth_password_reset'));
        $this->assertSame('billing_server_renewal_notice', EmailNotificationSetting::normalizeTemplateKey('billing_server_renewal_notice'));
    }
}
