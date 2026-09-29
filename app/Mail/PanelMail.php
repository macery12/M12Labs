<?php

namespace Everest\Mail;

use Everest\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;
use Illuminate\Mail\Mailables\Envelope;
use Everest\Services\Email\Templating\EmailTemplateRenderer;

/**
 * One built-in email type.
 *
 * A subclass's constructor parameters are exactly the variables its template
 * gets, so a missing or misspelt value is a type error where the email is
 * built, not a validation failure in the worker. Keep them to scalars: the
 * renderer refuses objects, and this is what gets serialized onto the queue.
 *
 * The body is the operator-editable Twig template, rendered under the sandbox
 * by EmailTemplateRenderer. Nothing here may hand template source to Blade.
 */
abstract class PanelMail extends Mailable
{
    /** Catalogue key, e.g. "auth.password_reset". */
    public const KEY = '';

    /**
     * Only ever set in the worker: the job is serialized before anything
     * renders, and a retry unserializes the original payload again.
     */
    private ?string $renderedHtml = null;

    abstract protected function subjectLine(): string;

    public function key(): string
    {
        return static::KEY;
    }

    /**
     * The template's view name: "auth.password_reset" renders
     * "emails/auth/password-reset.twig".
     */
    public static function viewFor(string $key): string
    {
        [$category, $action] = array_pad(explode('.', $key, 2), 2, '');

        return 'emails.' . $category . '.' . str_replace('_', '-', $action);
    }

    public function subjectText(): string
    {
        return $this->subjectLine();
    }

    /**
     * @return array<string, mixed> the constructor's parameters, by name
     */
    public function templateData(): array
    {
        $constructor = (new \ReflectionClass($this))->getConstructor();
        $data = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $data[$parameter->getName()] = $this->{$parameter->getName()};
        }

        return $data;
    }

    /**
     * Render the HTML body. Called by the send job before it hands the message
     * to the mailer, so a broken template is reported as such rather than as a
     * transport failure.
     */
    public function renderBody(): string
    {
        return $this->renderedHtml ??= app(EmailTemplateRenderer::class)->render(
            self::viewFor($this->key()),
            $this->templateData(),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    /**
     * The body is already HTML, and the text part is derived from it, so
     * neither goes through a Laravel view.
     */
    protected function buildView()
    {
        $html = $this->renderBody();

        return [
            'html' => new HtmlString($html),
            'text' => new HtmlString(self::htmlToText($html)),
        ];
    }

    protected static function displayName(User $user): string
    {
        return $user->username;
    }

    /**
     * When the event happened, in the panel's timezone. Taken while the event
     * is handled, not when the worker gets to the job.
     */
    protected static function now(): string
    {
        return now()->format('F j, Y g:i A');
    }

    protected static function billingCycle(?int $days): string
    {
        return match ($days) {
            null, 0 => 'One-time',
            1 => 'Daily',
            7 => 'Weekly',
            14 => 'Bi-weekly',
            30 => 'Monthly',
            60 => 'Bi-monthly',
            90 => 'Quarterly',
            180 => 'Semi-annually',
            365 => 'Annually',
            default => $days . ' days',
        };
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|h[1-6])>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<\/(div|tr|li)>/i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s+\n/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
