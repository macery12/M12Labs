<?php

namespace Everest\Services\Webhooks;

use Everest\Models\User;
use Everest\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;
use Everest\Contracts\Repository\ThemeRepositoryInterface;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

class WebhookEventService
{
    /**
     * WebhookEventService constructor.
     */
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ThemeRepositoryInterface $theme,
    ) {
    }

    /**
     * Convert hex color to integer.
     */
    private function hexToInt(string $hex): int
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return hexdec($hex);
    }

    /**
     * Fire the admin:jguard:registered webhook for a newly queued account.
     * Silently skips if webhooks are disabled or the event row is missing/disabled.
     */
    public function notifyJGuardRegistered(User $user, string $approvalMode, ?\Carbon\Carbon $expiresAt): void
    {
        if (!config('modules.webhooks.enabled')) {
            return;
        }

        $event = WebhookEvent::where('key', 'admin:jguard:registered')->first();
        if (!$event || !$event->enabled) {
            return;
        }

        $fields = [
            ['name' => 'Approval Mode', 'value' => ucfirst($approvalMode), 'inline' => true],
        ];

        if ($approvalMode === 'delayed' && $expiresAt !== null) {
            $fields[] = [
                'name' => 'Auto-Approves At',
                'value' => $expiresAt->toRfc7231String(),
                'inline' => true,
            ];
        }

        try {
            $this->send($user, $event, $fields);
        } catch (\Exception) {
            // Fire-and-forget; never block registration
        }
    }

    /**
     * Send a webhook through the defined URL.
     *
     * @param array<int, array{name: string, value: string, inline?: bool}> $fields optional Discord embed fields
     *
     * @throws \Exception
     */
    public function send(User $user, WebhookEvent $event, array $fields = []): void
    {
        $colorHex = $this->theme->get('theme::colors:primary');
        $url = $this->settings->get('settings::modules:webhooks:url');

        if (!$url) {
            throw new DisplayException('No Webhook URL has been defined.');
        }

        // Hex to integer
        $colorInt = $this->hexToInt($colorHex);

        $embed = [
            'title' => $event->key,
            'description' => $event->description,
            'url' => env('APP_URL') . '/admin',
            'color' => $colorInt,
            'timestamp' => now()->toIso8601String(),
            'footer' => [
                'text' => 'Jexactyl v4',
                'icon_url' => 'https://avatars.githubusercontent.com/u/91636558?s=200&v=4',
            ],
            'author' => [
                'name' => $user->email,
                'url' => env('APP_URL') . '/admin/users/' . $user->id,
            ],
        ];

        if (!empty($fields)) {
            $embed['fields'] = $fields;
        }

        try {
            Http::post($url, ['embeds' => [$embed]]);
        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to send webhook through URL.');
        }
    }
}
