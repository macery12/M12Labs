<?php

namespace Everest\Services\Email;

use Everest\Mail\PanelMail;
use Illuminate\Support\Str;
use Everest\Mail\ExtensionMail;
use Everest\Models\EmailDelivery;
use Illuminate\Support\Facades\Log;
use Everest\Jobs\Email\SendPanelMailJob;

/**
 * The one way panel mail gets sent: the event listener and extensions (through
 * Sdk\Services\PackageMail) both use it, so every message gets the same
 * switches, delivery log and retention.
 *
 * Decides here, at queue time, whether the message goes out at all, and
 * leaves the worker only the sending.
 */
class PanelMailer
{
    public function __construct(
        private EmailSettingsReader $settings,
        private EmailPolicyService $policy,
        private EmailDeliveryTracker $tracker,
        private ExtensionMailLimiter $extensionLimiter,
    ) {
    }

    /**
     * Queue a message. Returns its delivery row, or null when delivery is
     * switched off, which is not logged: with mail off there would be a row
     * for every login.
     */
    public function send(PanelMail $mail, string $recipient, ?int $userId = null, ?string $correlationId = null): ?EmailDelivery
    {
        if (!$this->settings->deliveryEnabled()) {
            Log::info('PanelMailer: email delivery is disabled, not sending', ['template_key' => $mail->key()]);

            return null;
        }

        // Billing events default their correlation id to ''.
        $correlationId = $correlationId ?: (string) Str::uuid();
        $existing = $this->tracker->find($correlationId);

        if ($existing !== null && ($existing->isPending() || $existing->isSuccessful())) {
            Log::info('PanelMailer: message already queued or sent', [
                'template_key' => $mail->key(),
                'correlation_id' => $correlationId,
            ]);

            return $existing;
        }

        $skip = match (true) {
            $this->policy->isBlockedRecipient($recipient) => 'Blocked recipient email',
            !EmailCatalogue::isLocked($mail->key()) && !$this->policy->isTemplateEnabled($mail->key()) => "Email type '{$mail->key()}' is disabled",
            // Last, so only a message that would otherwise go out counts.
            $mail instanceof ExtensionMail && !$this->extensionLimiter->attempt($mail->extensionId) => "Extension '{$mail->extensionId}' reached its hourly email limit",
            default => null,
        };

        $delivery = $this->tracker->record(
            mail: $mail,
            recipient: $recipient,
            correlationId: $correlationId,
            userId: $userId,
            provider: $this->settings->primary(),
            status: $skip === null ? EmailDelivery::STATUS_QUEUED : EmailDelivery::STATUS_SKIPPED,
            reason: $skip,
            existing: $existing,
        );

        if ($skip !== null) {
            Log::info('PanelMailer: not sending', ['template_key' => $mail->key(), 'reason' => $skip]);

            return $delivery;
        }

        $mail->to($recipient);

        SendPanelMailJob::dispatch($mail, $delivery->id);

        return $delivery;
    }
}
