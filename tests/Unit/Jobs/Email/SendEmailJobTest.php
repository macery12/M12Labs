<?php

namespace Everest\Tests\Unit\Jobs\Email;

use Mockery as m;
use Everest\Tests\TestCase;
use Everest\Jobs\Email\SendEmailJob;

class SendEmailJobTest extends TestCase
{
    public function testFailedLogsUseFailedStatusAndUnderscoreTemplateKey(): void
    {
        $emailLog = m::mock('alias:Everest\Models\EmailLog');
        $emailLog->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $payload) {
                $this->assertSame('auth_password_reset', $payload['template_key']);
                $this->assertSame('failed', $payload['status']);
                $this->assertFalse($payload['success']);

                return true;
            }));

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'user@example.com',
            data: ['userName' => 'Test'],
            userId: 15,
            correlationId: 'corr-123'
        );

        $job->failed(new \RuntimeException('Tags should only contain ASCII letters, numbers, underscores, or dashes.'));
    }
}
