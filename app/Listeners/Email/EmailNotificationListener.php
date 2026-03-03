<?php

namespace Everest\Listeners\Email;

use Everest\Jobs\Email\SendEmailJob;
use Everest\Models\EmailNotificationSetting;
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
        
        // Generate or get correlation ID (ONLY generate here, never in EmailManager)
        $correlationId = EmailTypeRegistry::getCorrelationId($event) 
            ?? \Illuminate\Support\Str::uuid()->toString();
        
        // Get user ID if available
        $userId = property_exists($event, 'user') && $event->user ? $event->user->id : null;

        // Centralized global kill-switch check before queueing any email work
        if (!EmailNotificationSetting::isGloballyEnabled()) {
            Log::info('EmailNotificationListener: Global email notifications disabled, skipping dispatch', [
                'event' => get_class($event),
                'template_key' => $templateKey,
                'recipient' => $recipient,
                'correlation_id' => $correlationId,
            ]);

            return;
        }

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
