<?php

namespace Everest\Listeners\Email;

use Everest\Jobs\Email\SendEmailJob;
use Everest\Services\Email\EmailTypeRegistry;
use Illuminate\Support\Facades\Log;

class EmailNotificationListener
{
    /**
     * Handle email notification events.
     */
    public function handle(object $event): void
    {
        // Get template key for this event
        $templateKey = EmailTypeRegistry::getTemplateKey($event);
        
        if (!$templateKey) {
            Log::debug('EmailNotificationListener: No template mapping for event', [
                'event' => get_class($event),
            ]);
            return;
        }

        // Extract recipient
        $recipient = EmailTypeRegistry::getRecipient($event);
        
        if (!$recipient) {
            Log::warning('EmailNotificationListener: No recipient found for event', [
                'event' => get_class($event),
                'template_key' => $templateKey,
            ]);
            return;
        }

        // Extract data
        $data = EmailTypeRegistry::extractDataFromEvent($event);
        
        // Get correlation ID
        $correlationId = EmailTypeRegistry::getCorrelationId($event);
        
        // Get user ID if available
        $userId = property_exists($event, 'user') && $event->user ? $event->user->id : null;

        Log::info('EmailNotificationListener: Dispatching email', [
            'event' => get_class($event),
            'template_key' => $templateKey,
            'recipient' => $recipient,
            'correlation_id' => $correlationId,
        ]);

        // Dispatch the job
        SendEmailJob::dispatch(
            $templateKey,
            $recipient,
            $data,
            $userId,
            $correlationId
        );
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): array
    {
        $mappings = EmailTypeRegistry::getAllMappings();
        $listeners = [];

        foreach ($mappings as $eventClass => $templateKey) {
            $listeners[$eventClass] = 'handle';
        }

        return $listeners;
    }
}
