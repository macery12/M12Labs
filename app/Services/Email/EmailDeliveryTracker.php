<?php

namespace Everest\Services\Email;

use Everest\Mail\PanelMail;
use Everest\Models\EmailDelivery;
use Everest\Models\EmailDeliveryAttempt;

/**
 * The only writer of email_deliveries and email_delivery_attempts.
 *
 * One delivery row per message, written when the message is queued (or
 * skipped), and one attempt row per time the job tried to send it.
 */
class EmailDeliveryTracker
{
    public function find(string $correlationId): ?EmailDelivery
    {
        return EmailDelivery::query()->where('correlation_id', $correlationId)->first();
    }

    /**
     * Write the delivery row for a message about to be queued or skipped.
     *
     * A correlation id that already has a row reuses it: the renewal notices
     * derive theirs from the server and date so a failed notice can be sent
     * again without a second row (the column is unique).
     */
    public function record(
        PanelMail $mail,
        string $recipient,
        string $correlationId,
        ?int $userId,
        string $provider,
        string $status = EmailDelivery::STATUS_QUEUED,
        ?string $reason = null,
        ?EmailDelivery $existing = null,
    ): EmailDelivery {
        $delivery = $existing ?? new EmailDelivery(['correlation_id' => $correlationId]);

        $delivery->fill([
            'template_key' => $mail->key(),
            'recipient' => $recipient,
            'user_id' => $userId,
            'subject' => $mail->subjectText(),
            'provider' => $provider,
            'status' => $status,
            'last_error' => $reason,
        ])->save();

        return $delivery;
    }

    public function startAttempt(EmailDelivery $delivery, string $provider): EmailDeliveryAttempt
    {
        // Numbered from the rows rather than the job's attempt count: a
        // resent delivery keeps its earlier attempts, and a job released by
        // the circuit breaker counts an attempt it never made.
        $number = (int) $delivery->deliveryAttempts()->max('attempt_number') + 1;

        $delivery->update([
            'status' => EmailDelivery::STATUS_SENDING,
            'last_attempt_at' => now(),
        ]);

        return EmailDeliveryAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => $number,
            'provider' => $provider,
            'started_at' => now(),
            'status' => EmailDelivery::STATUS_SENDING,
            'success' => false,
        ]);
    }

    public function succeeded(EmailDeliveryAttempt $attempt, string $provider, ?string $messageId): void
    {
        $attempt->fill([
            'provider' => $provider,
            'finished_at' => now(),
            'success' => true,
            'status' => EmailDelivery::STATUS_SENT,
            'provider_message_id' => $messageId,
        ]);
        $attempt->calculateDuration();
        $attempt->save();

        $attempt->delivery->update([
            'status' => EmailDelivery::STATUS_SENT,
            'provider' => $provider,
            'provider_message_id' => $messageId,
            'attempts' => $attempt->attempt_number,
            'sent_at' => $attempt->finished_at,
            'last_attempt_at' => $attempt->finished_at,
            'last_status_code' => null,
            'last_error' => null,
        ]);
    }

    /**
     * The delivery shows failed after every failed attempt, including one the
     * job will retry; the next attempt moves it back to sending.
     */
    public function failed(EmailDeliveryAttempt $attempt, MailFailure $failure, ?\Throwable $exception = null): void
    {
        $attempt->fill([
            'finished_at' => now(),
            'success' => false,
            'status' => EmailDelivery::STATUS_FAILED,
            'status_code' => $failure->code,
            'error' => $failure->message,
        ]);

        if ($exception !== null && $this->isDebugMode()) {
            $attempt->exception_class = get_class($exception);
            $attempt->stacktrace = $exception->getTraceAsString();
        }

        $attempt->calculateDuration();
        $attempt->save();

        $attempt->delivery->update([
            'status' => EmailDelivery::STATUS_FAILED,
            'attempts' => $attempt->attempt_number,
            'last_attempt_at' => $attempt->finished_at,
            'last_status_code' => $failure->code,
            'last_error' => $failure->message,
        ]);
    }

    private function isDebugMode(): bool
    {
        return (bool) (config('app.debug') || config('mail.log_debug', false));
    }
}
