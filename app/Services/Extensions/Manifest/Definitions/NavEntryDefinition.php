<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * How an extension's entry reads in the admin sidebar: the label and icon of
 * the one entry its admin pages fold under, and its order among the other
 * installed extensions.
 *
 * Deliberately no placement. Which group the entry sits in is the panel's
 * decision (Extensions by default) and the operator's to change, never the
 * package's, so installing an extension cannot push it in among core pages.
 */
final readonly class NavEntryDefinition implements \JsonSerializable
{
    public function __construct(
        public string $labelKey,
        public string $icon,
        public int $order,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'labelKey' => $this->labelKey,
            'icon' => $this->icon,
            'order' => $this->order,
        ];
    }
}
