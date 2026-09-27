import {
    Gauge,
    ShieldCheck,
    Boxes,
    Zap,
    Server,
    Cpu,
    HardDrive,
    Globe,
    Rocket,
    Lock,
    Database,
    Cloud,
    Activity,
    Layers,
    Cog,
    Heart,
    Star,
    Check,
    type LucideIcon,
} from 'lucide-react';

// Allowlist of icons selectable in the admin landing editor and resolvable on
// the public page. Keeping this fixed means stored config never references an
// arbitrary/unknown component. Unknown names fall back to a sensible default.
export const LANDING_ICONS: Record<string, LucideIcon> = {
    Gauge,
    ShieldCheck,
    Boxes,
    Zap,
    Server,
    Cpu,
    HardDrive,
    Globe,
    Rocket,
    Lock,
    Database,
    Cloud,
    Activity,
    Layers,
    Cog,
    Heart,
    Star,
    Check,
};

export const LANDING_ICON_NAMES = Object.keys(LANDING_ICONS);

/** True for a name on the allowlist, as opposed to a URL or free text. */
export function isIconName(name: string | null | undefined): name is string {
    return !!name && name in LANDING_ICONS;
}

export function resolveIcon(name: string | undefined): LucideIcon {
    return (name && LANDING_ICONS[name]) || Gauge;
}
