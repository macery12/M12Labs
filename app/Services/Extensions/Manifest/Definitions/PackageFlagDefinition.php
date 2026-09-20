<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * A named boolean the panel derives for one installed package.
 *
 * Every `all` predicate must pass. When `any` is present, at least one of its
 * predicates must pass as well. Keeping this expression flat makes it useful
 * for configured-then-enabled features without accepting arbitrary package
 * code or an unbounded expression tree in the bootstrap path.
 */
final readonly class PackageFlagDefinition implements \JsonSerializable
{
    /**
     * @param array<int, PackageFlagPredicate> $all
     * @param array<int, PackageFlagPredicate> $any
     */
    public function __construct(
        public string $name,
        public array $all = [],
        public array $any = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
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
