import { pageManifests, type ExtensionFrontendSlot, type ExtensionPageManifest } from './pages';

export const EXTENSION_SLOT_NAMES = ['server-layout.banner', 'server-layout.overlay'] as const;

export type ExtensionSlotName = (typeof EXTENSION_SLOT_NAMES)[number];

/** Metadata core passes to a declared slot entry. */
export interface ExtensionSlotProps {
    slot: ExtensionSlotName;
    extensionId: string;
    extensionVersion: string;
}

export interface ExtensionSlotDeclaration {
    dir: string;
    manifest: ExtensionPageManifest;
    slot: ExtensionFrontendSlot;
}

function isSlotName(value: unknown): value is ExtensionSlotName {
    return typeof value === 'string' && (EXTENSION_SLOT_NAMES as readonly string[]).includes(value);
}

/**
 * Flatten panel-generated manifests into safe, stably ordered declarations.
 *
 * PHP rejects malformed declarations at install. These checks are still kept
 * at the browser boundary so a stale or post-install edited generated file is
 * ignored rather than becoming a broken global mount.
 */
export function slotDeclarations(globbed: Record<string, unknown>): ExtensionSlotDeclaration[] {
    return pageManifests(globbed)
        .flatMap(({ dir, manifest }) =>
            (manifest.slots ?? []).flatMap(slot => {
                if (
                    typeof manifest.id !== 'string' ||
                    typeof manifest.version !== 'string' ||
                    !isSlotName(slot?.name) ||
                    typeof slot.entry !== 'string' ||
                    !/^[a-z][a-z0-9-]{0,31}$/.test(slot.entry) ||
                    !Number.isFinite(slot.order) ||
                    (slot.requiredServerPermission !== undefined &&
                        typeof slot.requiredServerPermission !== 'string')
                ) {
                    return [];
                }

                return [{ dir, manifest, slot }];
            }),
        )
        .sort(
            (a, b) =>
                a.slot.order - b.slot.order ||
                a.manifest.id.localeCompare(b.manifest.id) ||
                a.slot.entry.localeCompare(b.slot.entry),
        );
}
