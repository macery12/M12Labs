<?php

namespace Everest\Services\AI\Privacy;

use Everest\Models\Setting;
use Everest\Services\AI\ProviderFactory;
use Everest\Services\Privacy\PiiRedactor;
use Everest\Services\Privacy\RedactionMap;
use Everest\Services\AI\Data\ProviderConfig;

/**
 * When the AI module redacts, and which categories it sweeps.
 *
 * The engine itself is core's ({@see PiiRedactor}) because a core admin page
 * needs it too — see Everest\Services\Queue\FailedJobRedactor. What stayed
 * behind is everything that is genuinely a decision about *the AI module*: an
 * operator toggle, the category list they chose, and the rule that OpenRouter
 * always receives fully redacted context whatever they chose.
 *
 * Splitting it this way is what lets the two callers disagree. Redaction on its
 * way to an inference provider is an operator's call, because over-redacting
 * costs the model a fact it needed. Redaction on an admin page rendering a
 * failed job is not — there is no upside to showing the operator a customer's
 * address, so core sweeps unconditionally and never consults this class.
 *
 * Moves with the AI module when it becomes an extension.
 */
class AiRedactionPolicy
{
    public function __construct(private PiiRedactor $redactor)
    {
    }

    /**
     * Redact a decoded tool result, if the operator has redaction on.
     */
    public function redact(mixed $data, RedactionMap $map): mixed
    {
        if (!$this->enabled()) {
            return $data;
        }

        return $this->redactor->redact($data, $map, $this->activeKinds());
    }

    /**
     * Redact a block of free text — a console buffer, a file the model read.
     */
    public function redactText(string $text, RedactionMap $map): string
    {
        if (!$this->enabled()) {
            return $text;
        }

        return $this->redactor->redactText($text, $map, $this->activeKinds());
    }

    /**
     * Put exact known values back.
     *
     * Ungated on purpose, and a straight delegate. Restoring is not redacting:
     * it replaces tokens this map already issued, so with redaction off the map
     * is empty and this is a no-op anyway. Gating it would instead mean a
     * conversation that was redacted under one setting could not be restored
     * after the setting changed.
     */
    public function restore(string $text, RedactionMap $map): string
    {
        return $this->redactor->restore($text, $map);
    }

    public function enabled(): bool
    {
        if ($this->forced()) {
            return true;
        }

        return (bool) Setting::get(
            'settings::modules:ai:privacy:enabled',
            config('modules.ai.privacy.enabled', true)
        );
    }

    /**
     * The categories in force, stored as a JSON list alongside the tool policy.
     * An unset setting means the defaults, not "none" — an operator who never
     * opened the privacy panel should still be protected.
     *
     * @return string[]
     */
    public function activeKinds(): array
    {
        if ($this->forced()) {
            return PiiRedactor::KINDS;
        }

        $stored = Setting::get('settings::modules:ai:privacy:categories');

        if (!is_string($stored) || $stored === '') {
            return array_values(array_intersect(
                PiiRedactor::KINDS,
                (array) config('modules.ai.privacy.categories', PiiRedactor::DEFAULT_KINDS)
            ));
        }

        $decoded = json_decode($stored, true);

        if (!is_array($decoded)) {
            return PiiRedactor::DEFAULT_KINDS;
        }

        // Intersected against the canonical list so the order is the declared
        // one and an unknown category cannot reach the walker.
        return array_values(array_intersect(PiiRedactor::KINDS, $decoded));
    }

    /** OpenRouter always receives fully redacted panel context and tool output. */
    public function forced(): bool
    {
        return app(ProviderFactory::class)->provider() === ProviderConfig::PROVIDER_OPENROUTER;
    }
}
