<?php

namespace Everest\Jobs\Webhooks;

use Everest\Jobs\Job;
use Everest\Models\User;
use Everest\Models\WebhookEvent;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Everest\Services\Webhooks\WebhookEventService;

/**
 * Posts one admin webhook (Discord embed) off the request path.
 *
 * Activity logging used to post inline, with no timeout, so a slow or
 * blackholed webhook URL added up to Guzzle's 30 s default to every login or
 * server action that logged a subscribed event. The send is now bounded
 * (WebhookEventService::SEND_TIMEOUT_SECONDS) and retried here instead.
 *
 * Carries ids rather than models: the event is re-read when the job runs, so
 * an event an admin switched off in the meantime is not sent.
 */
#[Timeout(30)]
#[Tries(3)]
#[Backoff([10, 60])]
class SendWebhookJob extends Job implements ShouldQueue
{
    use Dispatchable;

    /**
     * @param array<int, array{name: string, value: string, inline?: bool}> $fields optional Discord embed fields
     */
    public function __construct(
        public int $userId,
        public string $eventKey,
        public array $fields = [],
    ) {
    }

    public function handle(WebhookEventService $webhooks): void
    {
        $user = User::query()->find($this->userId);
        $event = WebhookEvent::query()->where('key', $this->eventKey)->where('enabled', true)->first();

        // Nothing to retry: the user was deleted, the event switched off, or
        // the URL removed since this was queued.
        if ($user === null || $event === null || !$webhooks->configured()) {
            return;
        }

        $webhooks->send($user, $event, $this->fields);
    }
}
