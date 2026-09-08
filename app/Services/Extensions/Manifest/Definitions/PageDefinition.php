<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One navigable page an extension contributes.
 *
 * The slug names the entry file the loader resolves
 * (frontend/src/extensions/packages/<id>/pages/{server,admin}/<slug>.tsx) —
 * a package never supplies a path, so it cannot point page loading at a file
 * outside its own directory.
 */
final readonly class PageDefinition implements \JsonSerializable
{
    public function __construct(
        public string $slug,
        public string $labelKey,
        public string $icon,
        public string $category,
        public int $order,
        /** Core server permission required to see/visit a server page. */
        public ?string $requiredServerPermission = null,
        /** Action segment of ext.<id>.admin.<action> required for an admin page. */
        public ?string $requiredExtensionPermission = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'slug' => $this->slug,
            'labelKey' => $this->labelKey,
            'icon' => $this->icon,
            'category' => $this->category,
            'order' => $this->order,
            'requiredServerPermission' => $this->requiredServerPermission,
            'requiredExtensionPermission' => $this->requiredExtensionPermission,
        ], fn ($value) => $value !== null);
    }
}
