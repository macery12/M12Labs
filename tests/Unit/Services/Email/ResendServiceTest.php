<?php

namespace Everest\Tests\Unit\Services\Email;

use Mockery as m;
use Everest\Tests\TestCase;
use Everest\Services\Email\EmailMessage;
use Everest\Services\Email\ResendService;
use Everest\Services\Email\ResendHttpClient;

class ResendServiceTest extends TestCase
{
    public function testSendDoesNotCreateStandaloneEmailLogEntries(): void
    {
        m::mock('alias:Everest\Models\EmailLog')
            ->shouldReceive('create')
            ->never();

        $service = new ResendService('test-api-key');
        $client = m::mock(ResendHttpClient::class, ['test-api-key']);
        $client->shouldReceive('sendEmail')
            ->once()
            ->andReturn(['id' => 're_test_123']);

        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $client);

        $result = $service->send(new EmailMessage(
            to: 'user@example.com',
            subject: 'Subject',
            html: '<p>Hello</p>',
            from: 'noreply@example.com'
        ));

        $this->assertTrue($result->success);
        $this->assertSame('re_test_123', $result->messageId);
    }
}
