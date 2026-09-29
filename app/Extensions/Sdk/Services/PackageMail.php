<?php

namespace Everest\Extensions\Sdk\Services;

use Ramsey\Uuid\Uuid;
use Everest\Models\User;
use Everest\Mail\ExtensionMail;
use Everest\Services\Email\PanelMailer;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Services\Extensions\ExtensionRuntimePlanService;

/**
 * Email from an extension, sent the panel's way.
 *
 * Declare each kind of email under `capabilities.emails` and ship its body as
 * `emails/<type>.twig`; an administrator approves the list on install:
 *
 * ```json
 * "emails": [{
 *     "type": "ticket-reply",
 *     "labelKey": "ext.my_extension.emails.ticketReply",
 *     "subject": "New reply on {{ ticketTitle }}",
 *     "variables": [
 *         { "name": "ticketTitle", "description": "The ticket's title", "example": "Server won't start", "required": true },
 *         { "name": "ticketUrl", "description": "Link to the ticket", "example": "https://panel.example.com/tickets/4", "required": true }
 *     ]
 * }]
 * ```
 *
 * ```php
 * PackageMail::for('my_extension')->send('ticket-reply', $ticket->user, [
 *     'ticketTitle' => $ticket->title,
 *     'ticketUrl' => $url,
 * ], dedupeKey: 'reply-' . $reply->id);
 * ```
 *
 * The message goes through the same mailer as the panel's own: its providers
 * and sender, the operator's switch for this type, the delivery log, retries
 * and retention, and an hourly ceiling per extension that only the operator
 * sets. Sending any other way is refused at install by the source scanner.
 *
 * Recipients are panel accounts, never a bare address, so a package cannot be
 * turned into a relay for mail to people who have nothing to do with the
 * panel. The template also gets `userName` and `userEmail` for the recipient,
 * and the render context every panel email has (`appName`, `appUrl`,
 * `supportEmail`, …); it may `{% extends "layout.twig" %}` for the panel's
 * chrome.
 */
final class PackageMail
{
    private function __construct(
        private string $extensionId,
        private ExtensionRuntimePlanService $plan,
        private PanelMailer $mailer,
    ) {
    }

    public static function for(string $extensionId): self
    {
        ExtensionCallerGuard::assertCallerIs($extensionId);

        return new self($extensionId, app(ExtensionRuntimePlanService::class), app(PanelMailer::class));
    }

    /**
     * Queue one email to one panel user.
     *
     * Returns true when it is queued, or was already queued or sent under the
     * same `$dedupeKey`. False means the panel decided not to send it: email
     * is switched off, the operator turned this type off, the address is
     * blocked, or the extension spent its hourly allowance. Each of those but
     * the first is in the delivery log, so a false is not a failure to report.
     *
     * `$dedupeKey` makes a retried job or a redelivered hook safe: the same
     * key for the same type sends once. Without one every call sends.
     *
     * @param array<string, mixed> $variables exactly the declared variables: strings, numbers,
     *                                        booleans, null or \Stringable — checked here, since
     *                                        this is where a package's mistake should surface
     *
     * @throws \LogicException when the type is not declared, or this extension is disabled
     * @throws \InvalidArgumentException when the variables do not match the declaration
     */
    public function send(string $type, User $user, array $variables = [], ?string $dedupeKey = null): bool
    {
        $email = $this->plan->emailFor($this->extensionId, $type);

        if ($email === null) {
            throw new \LogicException(sprintf('Extension "%s" sent the email type "%s", which it has not declared. Add it to "capabilities.emails" in the manifest and ship emails/%s.twig; an administrator approves it on install. This also fires when the extension is disabled.', $this->extensionId, $type, $type));
        }

        $values = [];

        foreach ($variables as $name => $value) {
            if ($email->variable((string) $name) === null) {
                throw new \InvalidArgumentException(sprintf('Email type "%s" does not declare the variable "%s".', $type, $name));
            }

            if ($value instanceof \Stringable) {
                $value = (string) $value;
            }

            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf('Email variable "%s" must be a string, number, boolean or null; got %s.', $name, get_debug_type($value)));
            }

            $values[(string) $name] = $value;
        }

        foreach ($email->variables as $declared) {
            if ($declared->required && in_array($values[$declared->name] ?? null, [null, ''], true)) {
                throw new \InvalidArgumentException(sprintf('Email type "%s" requires the variable "%s".', $type, $declared->name));
            }
        }

        $values['userName'] = $user->username;
        $values['userEmail'] = $user->email;

        $mail = new ExtensionMail(
            extensionId: $this->extensionId,
            type: $type,
            renderedSubject: $email->subjectWith($values + ['appName' => (string) config('app.name')]),
            variables: $values,
        );

        $delivery = $this->mailer->send($mail, $user->email, $user->id, $this->correlationId($type, $dedupeKey));

        return $delivery !== null && ($delivery->isPending() || $delivery->isSuccessful());
    }

    /**
     * A stable id per extension, type and key, so the delivery log's unique
     * correlation id is what makes the second send a no-op.
     */
    private function correlationId(string $type, ?string $dedupeKey): ?string
    {
        if ($dedupeKey === null || $dedupeKey === '') {
            return null;
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('m12labs:extension-mail:%s:%s:%s', $this->extensionId, $type, $dedupeKey))->toString();
    }
}
