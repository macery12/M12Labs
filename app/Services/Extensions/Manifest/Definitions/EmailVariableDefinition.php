<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * A variable a package passes to one of its email templates. The description
 * and example are what the admin template editor shows, and the example is
 * what its preview renders with.
 */
final readonly class EmailVariableDefinition implements \JsonSerializable
{
    public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    public function __construct(
        public string $name,
        public string $description = '',
        public string $example = '',
        public bool $required = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'example' => $this->example,
            'required' => $this->required,
        ], fn ($value) => $value !== '' && $value !== false);
    }
}
