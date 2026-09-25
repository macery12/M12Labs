<?php

namespace Everest\Services\Navigation;

use Everest\Models\Setting;

/**
 * The operator's admin sidebar layout: group order, group names, which groups
 * start folded, where each entry sits (extensions included) and what is
 * hidden.
 *
 * Stored as one JSON blob, the way the landing page config is. `null` means
 * "never customised": the frontend then uses the order the route registry
 * declares. The layout names entries by id only; which entries exist, and
 * whether the viewer may open them, is still decided by the route registry and
 * its permission gates, so a layout can move or hide an entry but never reveal
 * one. Entries it does not mention (a newly installed extension, a page added
 * by a panel update) fall back to their default group.
 */
class AdminNavigationLayoutService
{
    public const KEY = 'settings::navigation:admin:layout';

    /**
     * @return array{groups: list<array{key: string, label: string|null, collapsed: bool, items: list<string>}>, hidden: list<string>}|null
     */
    public function get(): ?array
    {
        $raw = Setting::get(self::KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['groups']) || !is_array($decoded['groups'])) {
            return null;
        }

        return [
            'groups' => array_values($decoded['groups']),
            'hidden' => array_values(is_array($decoded['hidden'] ?? null) ? $decoded['hidden'] : []),
        ];
    }

    /**
     * @param array{groups: list<array<string, mixed>>, hidden?: list<string>}|null $layout
     */
    public function save(?array $layout): void
    {
        if ($layout === null) {
            Setting::forget(self::KEY);

            return;
        }

        $groups = array_map(fn (array $group): array => [
            'key' => (string) $group['key'],
            'label' => isset($group['label']) && trim((string) $group['label']) !== '' ? trim((string) $group['label']) : null,
            'collapsed' => (bool) $group['collapsed'],
            'items' => array_values(array_map('strval', $group['items'] ?? [])),
        ], $layout['groups']);

        Setting::set(self::KEY, json_encode([
            'groups' => array_values($groups),
            'hidden' => array_values(array_map('strval', $layout['hidden'] ?? [])),
        ], JSON_THROW_ON_ERROR));
    }
}
