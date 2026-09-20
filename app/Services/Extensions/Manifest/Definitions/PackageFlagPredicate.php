<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One panel-evaluated input to a package frontend flag.
 *
 * A predicate can inspect only a setting or the presence of a secret declared
 * by the same package. Secret values are never read, decrypted, or published.
 */
final readonly class PackageFlagPredicate implements \JsonSerializable
{
    public const SOURCE_SETTING = 'setting';

    public const SOURCE_SECRET = 'secret';

    public const OPERATOR_EQUALS = 'equals';

    public const OPERATOR_CONFIGURED = 'configured';

    public function __construct(
        public string $source,
        public string $key,
        public string $operator,
        public string|int|float|bool $expected,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            $this->source => $this->key,
            $this->operator => $this->expected,
        ];
    }
}
