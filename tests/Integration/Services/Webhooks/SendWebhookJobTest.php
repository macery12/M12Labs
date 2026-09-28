<?php

namespace Everest\Tests\Integration\Services\Webhooks;

use Everest\Models\User;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Everest\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Everest\Jobs\Webhooks\SendWebhookJob;
use Illuminate\Http\Client\RequestException;
use Everest\Services\Webhooks\WebhookEventService;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Activity logging runs inside logins and server actions, and used to post the
 * admin webhook inline with no timeout -- a blackholed URL held every such
 * request for Guzzle's 30 s default. It is queued now, and only for events the
 * admin has switched on.
 */
class SendWebhookJobTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const EVENT = 'test:webhook-queued';

    private User $user;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.webhooks.enabled', true);
        Setting::set('settings::modules:webhooks:url', 'https://discord.test/api/webhooks/1/abc');
        WebhookEvent::query()->create(['key' => self::EVENT, 'description' => 'Queued webhook test.', 'enabled' => true]);

        $this->user = User::factory()->create();
    }

    public function testASubscribedActivityIsQueuedNotSent(): void
    {
        Queue::fake();
        Http::fake();

        Activity::event(self::EVENT)->actor($this->user)->log();

        Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->userId === $this->user->id && $job->eventKey === self::EVENT);
        Http::assertNothingSent();
    }

    /** The per-event toggle on the admin Webhooks page is what decides. */
    public function testAnEventTheAdminSwitchedOffIsNotQueued(): void
    {
        Queue::fake();
        WebhookEvent::query()->where('key', self::EVENT)->update(['enabled' => false]);

        Activity::event(self::EVENT)->actor($this->user)->log();

        Queue::assertNotPushed(SendWebhookJob::class);
    }

    public function testNothingIsQueuedWithoutAUrl(): void
    {
        Queue::fake();
        Setting::set('settings::modules:webhooks:url', '');

        Activity::event(self::EVENT)->actor($this->user)->log();

        Queue::assertNotPushed(SendWebhookJob::class);
    }

    public function testTheJobPostsTheEmbed(): void
    {
        Http::fake(['discord.test/*' => Http::response(null, 204)]);

        (new SendWebhookJob($this->user->id, self::EVENT))->handle(app(WebhookEventService::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/api/webhooks/1/abc'
            && $request['embeds'][0]['title'] === self::EVENT
            && $request['embeds'][0]['author']['name'] === $this->user->email
            && $request['embeds'][0]['footer']['text'] === 'M12Labs ' . config('app.version')
            && !str_contains($request['embeds'][0]['footer']['icon_url'], 'githubusercontent'));
    }

    /** A failed post has to throw, or the job's retries never happen. */
    public function testAFailedPostThrowsSoTheJobRetries(): void
    {
        Http::fake(['discord.test/*' => Http::response('rate limited', 429)]);

        $this->expectException(RequestException::class);

        (new SendWebhookJob($this->user->id, self::EVENT))->handle(app(WebhookEventService::class));
    }

    public function testAnEventSwitchedOffAfterQueueingIsNotSent(): void
    {
        Http::fake();
        WebhookEvent::query()->where('key', self::EVENT)->update(['enabled' => false]);

        (new SendWebhookJob($this->user->id, self::EVENT))->handle(app(WebhookEventService::class));

        Http::assertNothingSent();
    }
}
