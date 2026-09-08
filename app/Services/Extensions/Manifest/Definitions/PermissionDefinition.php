<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One dynamic admin permission.
 *
 * The manifest declares only the final action segment and its display metadata;
 * the panel derives the full identifier as ext.<id>.admin.<action> so a package
 * cannot mint a permission in another extension's — or core's — namespace.
 */
final readonly class PermissionDefinition implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public ?string $descriptionKey = null,
        /** Marks a destructive action. Blocked entirely for unsigned packages. */
        public bool $dangerous = false,
    ) {
    }

    public function identifier(string $extensionId): string
    {
        return sprintf('ext.%s.admin.%s', $extensionId, $this->key);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'key' => $this->key,
            'labelKey' => $this->labelKey,
            'descriptionKey' => $this->descriptionKey,
            'dangerous' => $this->dangerous,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
