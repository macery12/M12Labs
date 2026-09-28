<?php

namespace Everest\Services\Webhooks;

use Everest\Models\User;
use Everest\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;
use Everest\Jobs\Webhooks\SendWebhookJob;
use Everest\Contracts\Repository\ThemeRepositoryInterface;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

class WebhookEventService
{
    /**
     * A webhook is advisory. Discord answers in well under a second, and a
     * URL that does not answer in five is not going to.
     */
    public const SEND_TIMEOUT_SECONDS = 5;

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
     * Whether a webhook URL is set at all. Checked before queueing, so an
     * install that never configured webhooks queues nothing.
     */
    public function configured(): bool
    {
        return (string) $this->settings->get('settings::modules:webhooks:url', '') !== '';
    }

    /**
     * Queue the webhook for an event, if webhooks are on, a URL is set and
     * the admin has that event switched on.
     *
     * @param array<int, array{name: string, value: string, inline?: bool}> $fields optional Discord embed fields
     */
    public function dispatch(int $userId, string $eventKey, array $fields = []): void
    {
        if (!config('modules.webhooks.enabled') || !$this->configured()) {
            return;
        }

        if (!WebhookEvent::query()->where('key', $eventKey)->where('enabled', true)->exists()) {
            return;
        }

        SendWebhookJob::dispatch($userId, $eventKey, $fields);
    }

    /**
     * Fire the admin:jguard:registered webhook for a newly queued account.
     */
    public function notifyJGuardRegistered(User $user, string $approvalMode, ?\Carbon\Carbon $expiresAt): void
    {
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
            $this->dispatch($user->id, 'admin:jguard:registered', $fields);
        } catch (\Exception) {
            // Silently ignored — webhook failure must never block registration.
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
        $colorHex = (string) $this->theme->get('theme::colors:primary', '#5865F2');
        $url = (string) $this->settings->get('settings::modules:webhooks:url', '');
        $appUrl = (string) config('app.url', '');

        if (!$url) {
            throw new DisplayException('No Webhook URL has been defined.');
        }

        // Hex to integer
        $colorInt = $this->hexToInt($colorHex);

        $embed = [
            'title' => $event->key,
            'description' => $event->description,
            'url' => rtrim($appUrl, '/') . '/admin',
            'color' => $colorInt,
            'timestamp' => now()->toIso8601String(),
            'footer' => [
                'text' => 'M12Labs ' . config('app.version'),
                // The operator's own logo, as the page's favicon uses it,
                // falling back to the panel's bundled icon on this host.
                'icon_url' => config('app.logo') ?: rtrim($appUrl, '/') . '/favicons/android-chrome-192x192.png',
            ],
            'author' => [
                'name' => $user->email,
                'url' => rtrim($appUrl, '/') . '/admin/users/' . $user->id,
            ],
        ];

        if (!empty($fields)) {
            $embed['fields'] = $fields;
        }

        // Throws on a timeout or an error response, so SendWebhookJob retries.
        Http::timeout(self::SEND_TIMEOUT_SECONDS)->post($url, ['embeds' => [$embed]])->throw();
    }
}
