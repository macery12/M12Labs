<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Models\User;
use Everest\Services\Email\EmailTypeRegistry;
use Everest\Events\Email\PasswordResetRequested;
use Everest\Tests\TestCase;

class EmailTypeRegistryTest extends TestCase
{
    public function testPasswordResetEventMapsToUnderscoreTemplateKey(): void
    {
        $user = new User();
        $user->forceFill([
            'username' => 'test',
            'email' => 'user@example.com',
        ]);

        $event = new PasswordResetRequested(
            user: $user,
            resetUrl: 'https://example.com/reset',
            correlationId: 'corr-123'
        );

        $this->assertSame('auth_password_reset', EmailTypeRegistry::getTemplateKey($event));
    }

    public function testAllowedVariablesSupportsDotOrUnderscoreTemplateKey(): void
    {
        $underscore = EmailTypeRegistry::getAllowedVariables('auth_password_reset');
        $dot = EmailTypeRegistry::getAllowedVariables('auth.password_reset');

        $this->assertSame(['userName', 'resetUrl', 'expiresIn'], $underscore);
        $this->assertSame($underscore, $dot);
    }
}
