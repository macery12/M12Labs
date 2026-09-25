<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Services\Privacy\PiiRedactor;
use Everest\Services\Privacy\RedactionMap;

/**
 * The panel's PII redaction engine.
 *
 * Replaces emails, IPs, tokens and the rest with stable placeholders before a
 * value leaves the panel, and puts them back afterwards. A package needs this
 * whenever it sends operator or customer data to something outside the panel --
 * a model provider, a webhook, an export -- and needs the reply to still make
 * sense when it comes back naming `[email_1]`.
 *
 * Wrapped rather than reimplemented on purpose. There is one regex set for this
 * in the panel and there should stay one: a second copy drifts, and the copy
 * that drifts is the one nobody looks at. The engine is also what the admin
 * Queues page runs over failed job payloads, so a package fixing a gap here
 * fixes it there too.
 *
 * The {@see RedactionMap} is the caller's to own and persist. It holds the
 * placeholder/value pairs for one conversation or one job, so restoring a reply
 * needs the same map that redacted the request -- which is why this class does
 * not keep one. Create it with `new RedactionMap()`, store it with `toArray()`,
 * and bring it back with `RedactionMap::fromArray()`.
 *
 * Which categories run is the caller's decision, not a panel setting: the
 * engine takes its kinds as an argument so that a package's own privacy policy
 * (which categories an operator switched on) stays the package's business.
 */
final class PackageRedaction
{
    private function __construct(private PiiRedactor $redactor)
    {
    }

    public static function engine(): self
    {
        return new self(app(PiiRedactor::class));
    }

    /**
     * Every category the engine knows how to find.
     *
     * @return array<int, string>
     */
    public static function allKinds(): array
    {
        return PiiRedactor::KINDS;
    }

    /**
     * The categories the panel sweeps unless told otherwise.
     *
     * @return array<int, string>
     */
    public static function defaultKinds(): array
    {
        return PiiRedactor::DEFAULT_KINDS;
    }

    /**
     * Redact a structure -- array, string, or scalar -- in place of its values.
     *
     * @param array<int, string>|null $kinds null sweeps every category
     */
    public function redact(mixed $data, RedactionMap $map, ?array $kinds = null): mixed
    {
        return $this->redactor->redact($data, $map, $kinds ?? PiiRedactor::KINDS);
    }

    /** @param array<int, string>|null $kinds null sweeps every category */
    public function redactText(string $text, RedactionMap $map, ?array $kinds = null): string
    {
        return $this->redactor->redactText($text, $map, $kinds ?? PiiRedactor::KINDS);
    }

    /**
     * Put the real values back.
     *
     * Only the placeholders this map minted are restored, so text that happens
     * to contain `[email_1]` without a matching entry is left alone rather than
     * being filled in with somebody else's address.
     */
    public function restore(string $text, RedactionMap $map): string
    {
        return $this->redactor->restore($text, $map);
    }
}
