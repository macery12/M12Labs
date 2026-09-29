<?php

namespace Everest\Jobs\Email;

use Everest\Jobs\Job;
use Everest\Mail\PanelMail;
use Illuminate\Bus\Queueable;
use Everest\Models\EmailDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Everest\Services\Email\MailFailure;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\InteractsWithQueue;
use Everest\Services\Email\DeliveryReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Services\Email\EmailDeliveryTracker;
use Everest\Services\Email\PanelMailerConfigurator;
use Illuminate\Queue\Middleware\ThrottlesExceptions;

/**
 * Sends one message queued by PanelMailer, and owns its retries and its
 * attempt rows. Laravel's own queued mailables would own both, which loses
 * the circuit breaker and the split between failures worth retrying and
 * ones that are not.
 *
 * Runs on the `mail` lane. Password resets and payment receipts are the most
 * latency-sensitive work the panel queues, so they get their own worker rather
 * than sharing one with hour-long installs.
 */
#[Timeout(120)]
#[MaxExceptions(2)]
class SendPanelMailJob extends Job implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(public PanelMail $mail, public int $deliveryId)
    {
    }

    /**
     * When the mail provider is down, every queued email fails in turn and each
     * one pays the full backoff. Circuit-breaking after a handful of failures
     * parks the rest cheaply instead of grinding the worker through the backlog,
     * and they resume on their own once the provider recovers.
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(5, 300))->by('email-provider')];
    }

    public function handle(PanelMailerConfigurator $configurator, EmailDeliveryTracker $tracker, EmailSettingsReader $settings): void
    {
        $delivery = EmailDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            Log::warning('SendPanelMailJob: delivery row is gone, not sending', ['delivery_id' => $this->deliveryId]);

            return;
        }

        // A retry of an attempt whose send went through before the worker died.
        if ($delivery->isSuccessful()) {
            return;
        }

        $attempt = $tracker->startAttempt($delivery, $settings->primary());

        try {
            $providers = $configurator->configure();
        } catch (\Throwable $e) {
            $tracker->failed($attempt, MailFailure::from($e), $e);

            return;
        }

        $attempt->update(['provider' => implode('+', $providers)]);

        try {
            $this->mail->renderBody();
        } catch (\Throwable $e) {
            $tracker->failed($attempt, MailFailure::render($e), $e);

            return;
        }

        try {
            $sent = Mail::mailer(PanelMailerConfigurator::MAILER)->send($this->mail);
        } catch (\Throwable $e) {
            $failure = MailFailure::from($e);
            $tracker->failed($attempt, $failure, $e);

            if (!$failure->retryable()) {
                Log::warning('SendPanelMailJob: send failed and will not be retried', [
                    'delivery_id' => $delivery->id,
                    'kind' => $failure->kind,
                    'error' => $failure->message,
                ]);

                return;
            }

            // Rethrown for the job's backoff and the circuit breaker.
            throw $e;
        }

        $receipt = DeliveryReceipt::from($sent, $providers);

        $tracker->succeeded($attempt, $receipt->provider, $receipt->messageId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendPanelMailJob: job failed permanently', [
            'delivery_id' => $this->deliveryId,
            'template_key' => $this->mail->key(),
            'error' => $exception->getMessage(),
        ]);
    }
}
