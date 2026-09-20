import http from '@/lib/http';
import { useFlags } from '@/state/flags';
import type { ExtensionFlagValues } from './pages';

const EMPTY_FLAGS: Readonly<Record<string, boolean>> = Object.freeze({});

export interface ExtensionFrontendState {
    active: string[];
    flags: ExtensionFlagValues;
}

/** Boolean-only package state already exposed in the authenticated bootstrap. */
export function useExtensionFlags(extensionId: string): Readonly<Record<string, boolean>> {
    return useFlags(state => state.everest?.extensions.flags?.[extensionId] ?? EMPTY_FLAGS);
}

export function useExtensionFlag(extensionId: string, name: string): boolean {
    return useFlags(state => state.everest?.extensions.flags?.[extensionId]?.[name] === true);
}

/**
 * Re-evaluate flags after a package settings/secret mutation and update every
 * route and slot gate from the server-owned result.
 */
export async function refreshExtensionFlags(): Promise<ExtensionFrontendState> {
    const { data } = await http.get<{ object: 'extension_flags'; attributes: ExtensionFrontendState }>(
        '/api/client/extensions/flags',
    );
    const snapshot = data.attributes;

    useFlags.setState(state => {
        if (!state.everest) return state;

        return {
            everest: {
                ...state.everest,
                extensions: {
                    ...state.everest.extensions,
                    active: snapshot.active,
                    flags: snapshot.flags,
                },
            },
        };
    });

    return snapshot;
}
