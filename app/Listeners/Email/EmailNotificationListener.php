<?php

namespace Everest\Listeners\Email;

use Everest\Models\User;
use Illuminate\Support\Facades\Log;
use Everest\Services\Email\PanelMailer;
use Everest\Services\Email\EmailCatalogue;
use Everest\Services\Email\EmailSettingsReader;

/**
 * Turns each email event into its Mailable and hands it to PanelMailer.
 *
 * Runs in the request that fired the event, so the Mailable is built from
 * the state at that moment (the time, the request IP), and only the send is
 * left to the worker.
 */
class EmailNotificationListener
{
    public function __construct(private PanelMailer $mailer, private EmailSettingsReader $settings)
    {
    }

    public function handle(object $event): void
    {
        // Checked again by PanelMailer; this just skips building the message.
        if (!$this->settings->deliveryEnabled()) {
            return;
        }

        $type = EmailCatalogue::forEvent($event);
        $user = property_exists($event, 'user') ? $event->user : null;

        if ($type === null || !$user instanceof User || empty($user->email)) {
            Log::warning('EmailNotificationListener: no email type or recipient for event', ['event' => get_class($event)]);

            return;
        }

        $this->mailer->send(
            $type->mailFor($event),
            $user->email,
            $user->id,
            property_exists($event, 'correlationId') ? $event->correlationId : null,
        );
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): array
    {
        return array_fill_keys(EmailCatalogue::events(), 'handle');
    }
}
