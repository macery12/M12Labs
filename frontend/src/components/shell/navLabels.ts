import { td } from '@/i18n/messages';
import type { NavItem } from '@/routes/nav';

// Nav labels come from the route registry (dynamic English strings); look each
// up under nav.items.* with the English name as the fallback so an
// unregistered route still renders. An item carrying an explicit labelKey —
// extension pages, whose labels live in the package's own ext.<id>.* catalog
// rather than under nav.items.* — is resolved from that id instead, and an
// extension's manifest name (`label`) renders verbatim. Built-in categories
// are a fixed set; a group the operator named carries its own label instead.
export const navItemLabel = (item: NavItem) =>
    item.label ?? (item.labelKey ? td(item.labelKey, item.name) : td(`nav.items.${item.name}`, item.name));
export const navCategoryLabel = (cat: string) => td(`nav.category.${cat}`, cat);
