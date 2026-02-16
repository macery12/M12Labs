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

    public function testAllowedVariablesSupportsNormalizedCaseAndWhitespace(): void
    {
        $normalized = EmailTypeRegistry::getAllowedVariables(' auth_password_reset ');
        $legacy = EmailTypeRegistry::getAllowedVariables(' AUTH.PASSWORD_RESET ');

        $this->assertSame(['userName', 'resetUrl', 'expiresIn'], $normalized);
        $this->assertSame($normalized, $legacy);
    }

    public function testValidateVariablesAllowsPasswordResetPayloadForUnderscoreTemplate(): void
    {
        [$validData, $errors] = EmailTypeRegistry::validateVariables('auth_password_reset', [
            'userName' => 'user',
            'resetUrl' => 'https://example.com/reset',
            'expiresIn' => '60 minutes',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([
            'userName' => 'user',
            'resetUrl' => 'https://example.com/reset',
            'expiresIn' => '60 minutes',
        ], $validData);
    }

    public function testLegacyDotTemplateCandidateUsesFirstUnderscoreSplit(): void
    {
        $reflection = new \ReflectionClass(EmailTypeRegistry::class);
        $method = $reflection->getMethod('toLegacyDotTemplateKey');
        $method->setAccessible(true);

        $this->assertSame('simple', $method->invoke(null, 'simple'));
        $this->assertSame('auth.reset', $method->invoke(null, 'auth.reset'));
        $this->assertSame('auth.password_reset', $method->invoke(null, 'auth_password_reset'));
        $this->assertSame('auth.password_reset_confirm', $method->invoke(null, 'auth_password_reset_confirm'));
        $this->assertSame('billing.server_renewal_notice', $method->invoke(null, 'billing_server_renewal_notice'));
    }
}
