<?php

namespace Everest\Jobs\Email;

use Everest\Jobs\Job;
use Everest\Models\DeferredEmail;
use Everest\Models\EmailQuota;
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
    public function handle(): void
    {
        Log::info('ProcessDeferredEmailsJob: Starting');

        $pendingEmails = DeferredEmail::getPendingEmails(100);

        Log::info('ProcessDeferredEmailsJob: Found pending emails', [
            'count' => $pendingEmails->count(),
        ]);

        foreach ($pendingEmails as $deferred) {
            // Check if quota is now available
            $quota = EmailQuota::getOrCreateForUser($deferred->user_id);
            
            if ($quota->reserveQuota(1)) {
                // Quota available - dispatch the email
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

                $deferred->markAsSent();
            } else {
                // Still no quota - reschedule
                $nextAvailable = $quota->getNextAvailableTime();
                
                if ($nextAvailable->isAfter($deferred->scheduled_at)) {
                    $deferred->scheduled_at = $nextAvailable;
                    $deferred->incrementAttempts();
                    $deferred->save();

                    Log::info('ProcessDeferredEmailsJob: Rescheduling email', [
                        'deferred_id' => $deferred->id,
                        'scheduled_at' => $nextAvailable,
                        'attempts' => $deferred->attempts,
                    ]);
                }
            }
        }

        Log::info('ProcessDeferredEmailsJob: Completed');
    }
}
