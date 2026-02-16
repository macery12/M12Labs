<?php

namespace Everest\Tests\Unit\Jobs\Email;

use Mockery as m;
use Everest\Tests\TestCase;
use Everest\Jobs\Email\SendEmailJob;
use Everest\Services\Email\EmailManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SendEmailJobTest extends TestCase
{
    public function testFailedLogsUseFailedStatusAndTemplateKeyAsProvided(): void
    {
        $emailLog = m::mock('alias:Everest\Models\EmailLog');
        $emailLog->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $payload) {
                $this->assertSame('auth.password_reset', $payload['template_key']);
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

    public function testHandleSuppressesDuplicateDispatchForSameCorrelationContext(): void
    {
        Cache::shouldReceive('add')
            ->once()
            ->with(
                'email_dispatch:corr-123:auth.password_reset:user@example.com',
                true,
                m::on(function ($ttl) {
                    return $ttl instanceof Carbon && abs($ttl->diffInSeconds(now()->addSeconds(45), false)) <= 1;
                })
            )
            ->andReturn(false);

        $emailManager = m::mock(EmailManager::class);
        $emailManager->shouldReceive('sendFromTemplate')->never();

        $job = new SendEmailJob(
            templateKey: 'auth.password_reset',
            recipient: 'user@example.com',
            data: ['userName' => 'Test'],
            userId: 15,
            correlationId: 'corr-123'
        );

        $job->handle($emailManager);
    }
}
