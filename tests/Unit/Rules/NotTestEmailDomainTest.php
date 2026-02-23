<?php

namespace Everest\Tests\Unit\Rules;

use Everest\Rules\NotTestEmailDomain;
use Everest\Tests\TestCase;

class NotTestEmailDomainTest extends TestCase
{
    public function testFailsForConfiguredTestDomains(): void
    {
        config(['email.test_domains' => ['example.com', 'test.com']]);

        $rule = new NotTestEmailDomain();

        $this->assertFalse($rule->passes('email', 'user@example.com'));
        $this->assertFalse($rule->passes('email', 'user@TEST.com'));
    }

    public function testPassesForNonTestDomains(): void
    {
        config(['email.test_domains' => ['example.com', 'test.com']]);

        $rule = new NotTestEmailDomain();

        $this->assertTrue($rule->passes('email', 'user@valid.com'));
        $this->assertTrue($rule->passes('email', 'user@sub.example.org'));
    }

    public function testHelperDetectsTestDomain(): void
    {
        config(['email.test_domains' => ['example.com', 'test.com']]);

        $this->assertTrue(is_test_domain('name@example.com'));
        $this->assertFalse(is_test_domain('name@domain.com'));
    }

    public function testMessage(): void
    {
        $this->assertSame('Enter a valid email address.', (new NotTestEmailDomain())->message());
    }
}
