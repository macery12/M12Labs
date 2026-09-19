/// <reference types="vite/client" />
import { lazy, Suspense, type ComponentType, type LazyExoticComponent, type ReactNode } from 'react';
import { can } from '@/lib/can';
import { useFlags } from '@/state/flags';
import { useServer } from '@/components/server/ServerContext';
import { ExtensionErrorBoundary } from '@/extensions-sdk/ExtensionErrorBoundary';
import {
    slotDeclarations,
    type ExtensionSlotName,
    type ExtensionSlotProps,
} from '@/extensions-sdk/slots';

interface SlotContribution {
    extensionId: string;
    extensionVersion: string;
    name: ExtensionSlotName;
    entry: string;
    order: number;
    requiredServerPermission?: string;
    component: LazyExoticComponent<ComponentType<ExtensionSlotProps>>;
}

// The JSON is panel-generated from the verified manifest. Entry modules stay
// lazy: importing this registry adds only the small declarations to the core
// graph, never an installed package's component code.
const manifests = import.meta.glob('../packages/*/extension.pages.json', {
    eager: true,
    import: 'default',
});

const slotModules = import.meta.glob('../packages/*/slots/*.tsx') as Record<
    string,
    () => Promise<{ default: ComponentType<ExtensionSlotProps> }>
>;

export const extensionSlotContributions: SlotContribution[] = slotDeclarations(manifests).flatMap(
    ({ dir, manifest, slot }) => {
        const loader = slotModules[`${dir}slots/${slot.entry}.tsx`];

        // Install validation requires the pair. If bytes change afterwards,
        // fail closed by not mounting an entry guessed from the filesystem.
        if (!loader) return [];

        return [
            {
                extensionId: manifest.id,
                extensionVersion: manifest.version,
                name: slot.name,
                entry: slot.entry,
                order: slot.order,
                requiredServerPermission: slot.requiredServerPermission,
                component: lazy(loader),
            },
        ];
    },
);

/**
 * Mount every enabled contribution to one server-layout slot.
 *
 * Each entry owns a boundary and Suspense instance. A failed import or render
 * disappears and is reported with package identity, while sibling extensions
 * and the core server shell continue running.
 */
export function ServerExtensionSlot({ name }: { name: ExtensionSlotName }): ReactNode {
    const flags = useFlags(state => state.everest);
    const server = useServer();
    const active = flags?.extensions.active ?? [];

    if (!flags?.extensions.enabled) return null;

    const visible = extensionSlotContributions.filter(
        contribution =>
            contribution.name === name &&
            active.includes(contribution.extensionId) &&
            can(server.permissions ?? [], contribution.requiredServerPermission),
    );

    if (visible.length === 0) return null;

    return (
        <>
            {visible.map(contribution => {
                const Entry = contribution.component;

                return (
                    <ExtensionErrorBoundary
                        key={`${contribution.extensionId}:${contribution.entry}:${contribution.extensionVersion}`}
                        extensionId={contribution.extensionId}
                        version={contribution.extensionVersion}
                        fallback={null}
                    >
                        <Suspense fallback={null}>
                            <Entry
                                slot={name}
                                extensionId={contribution.extensionId}
                                extensionVersion={contribution.extensionVersion}
                            />
                        </Suspense>
                    </ExtensionErrorBoundary>
                );
            })}
        </>
    );
}
