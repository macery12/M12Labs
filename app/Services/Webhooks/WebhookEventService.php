<?php

namespace Everest\Services\Webhooks;

use Everest\Models\User;
use Everest\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;

class WebhookEventService
{
    /**
     * Send a webhook through the defined URL.
     *
     * @throws \Exception
     */
    public function send(User $user, WebhookEvent $event): void
    {
        $url = config('modules.webhooks.url');

        if (!$url) {
            throw new DisplayException('No Webhook URL has been defined.');
        }

        try {
            Http::post($url, [
                'embeds' => [[
                    'title' => $event->key,
                    'description' => $event->description,
                    'url' => env('APP_URL') . '/admin',
                    'timestamp' => now()->toIso8601String(),
                    'footer' => [
                        'text' => 'Provided by Jexpanel v4',
                        'icon_url' => 'https://avatars.githubusercontent.com/u/91636558',
                    ],
                    'author' => [
                        'name' => $user->email,
                        'url' => env('APP_URL') . '/admin/users/' . $user->id,
                    ],
                ]],
            ]);
        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to send webhook through URL.');
        }
    }
}
