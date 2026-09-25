<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * When a settings field or credential is relevant enough to show.
 *
 * The same flat `all`/`any` expression as a {@see PackageFlagDefinition}, over
 * the same predicates, so a manifest author learns one grammar. Every `all`
 * predicate must pass; when `any` is present, at least one of its predicates
 * must pass as well. Unlike a flag, a condition may only read the package's own
 * *settings*: it is evaluated in the browser against the unsaved form, and a
 * secret's configured state is not something the form is editing.
 *
 * This is presentation only. A field whose condition is false is still stored,
 * still validated and still readable by the package -- hiding the Ollama
 * keep-alive while Anthropic is selected must not discard the value somebody
 * will want back when they switch.
 */
final readonly class VisibilityCondition implements \JsonSerializable
{
    /**
     * @param array<int, PackageFlagPredicate> $all
     * @param array<int, PackageFlagPredicate> $any
     */
    public function __construct(
        public array $all = [],
        public array $any = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'all' => array_map(
                fn (PackageFlagPredicate $predicate): array => $predicate->jsonSerialize(),
                $this->all
            ),
            'any' => array_map(
                fn (PackageFlagPredicate $predicate): array => $predicate->jsonSerialize(),
                $this->any
            ),
        ], fn (mixed $value): bool => $value !== []);
    }
}
