<?php

namespace Everest\Jobs\Email;

use Everest\Jobs\Job;
use Everest\Models\DeferredEmail;
use Everest\Services\Email\EmailDeliveryTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDeferredEmailsJob extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(EmailDeliveryTracker $tracker): void
    {
        Log::info('ProcessDeferredEmailsJob: Starting');

        $pendingEmails = DeferredEmail::getPendingEmails(100);

        Log::info('ProcessDeferredEmailsJob: Found pending emails', [
            'count' => $pendingEmails->count(),
        ]);

        foreach ($pendingEmails as $deferred) {
            Log::info('ProcessDeferredEmailsJob: Dispatching deferred email', [
                'deferred_id' => $deferred->id,
                'template_key' => $deferred->template_key,
                'user_id' => $deferred->user_id,
            ]);

            SendEmailJob::dispatch(
                $deferred->template_key,
                $deferred->recipient,
                $deferred->data,
                $deferred->user_id,
                $deferred->correlation_id
            );

            if ($deferred->correlation_id && ($delivery = $tracker->findByCorrelationId($deferred->correlation_id))) {
                $tracker->markQueued($delivery);
            }

            $deferred->delete();
        }

        Log::info('ProcessDeferredEmailsJob: Completed');
    }
}
