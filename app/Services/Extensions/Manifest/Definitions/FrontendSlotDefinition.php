<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One package component mounted into a panel-owned frontend location.
 *
 * The slot name comes from a closed panel vocabulary. The entry is only a
 * slug: it resolves to frontend/src/extensions/packages/<id>/slots/<entry>.tsx,
 * so a manifest cannot point the loader at core code or another package.
 */
final readonly class FrontendSlotDefinition implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public string $entry,
        public int $order = 100,
        /** Core server permission required before the contribution is mounted. */
        public ?string $requiredServerPermission = null,
        /** Package-owned boolean flags that must all be true. */
        public array $requiredFlags = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'entry' => $this->entry,
            'order' => $this->order,
            'requiredServerPermission' => $this->requiredServerPermission,
            'requiredFlags' => $this->requiredFlags,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
