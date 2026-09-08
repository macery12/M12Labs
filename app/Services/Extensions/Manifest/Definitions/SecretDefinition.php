<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * A credential the extension stores in the encrypted secret store.
 *
 * Only declared keys can be written or read, and values never appear in the
 * catalog API, logs, queue payloads or the diagnostic export — the manifest
 * carries the key and its label, never a default value.
 */
final readonly class SecretDefinition implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public ?string $helpKey = null,
        public bool $rotatable = true,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'key' => $this->key,
            'labelKey' => $this->labelKey,
            'helpKey' => $this->helpKey,
            'rotatable' => $this->rotatable,
        ], fn ($value) => $value !== null);
    }
}
