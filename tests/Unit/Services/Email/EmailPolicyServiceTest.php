<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Tests\TestCase;
use Everest\Services\Email\EmailPolicyService;
use Everest\Services\Email\EmailSettingsReader;

class EmailPolicyServiceTest extends TestCase
{
    public function testItUsesReaderForDeliveryEnabledAndRecipientBlocking(): void
    {
        config(['email.domain_blacklist' => []]);

        $settings = \Mockery::mock(EmailSettingsReader::class);
        $settings->shouldReceive('deliveryEnabled')->once()->andReturn(true);
        $policy = new EmailPolicyService($settings);

        $this->assertTrue($policy->isDeliveryEnabled());
        $this->assertFalse($policy->isBlockedRecipient('person@example.com'));
        $this->assertTrue($policy->isBlockedRecipient('not-an-email'));
    }
}
