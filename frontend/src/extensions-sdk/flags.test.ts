import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { EverestConfiguration } from '@/lib/globals';
import { useFlags } from '@/state/flags';
import http from '@/lib/http';
import { refreshExtensionFlags } from './flags';

vi.mock('@/lib/http', () => ({
    default: { get: vi.fn() },
}));

describe('refreshExtensionFlags', () => {
    beforeEach(() => {
        vi.mocked(http.get).mockReset();
        useFlags.setState({
            everest: {
                extensions: { enabled: true, active: ['old'], flags: { old: { visible: true } } },
            } as unknown as EverestConfiguration,
        });
    });

    it('replaces package state only with the server-evaluated snapshot', async () => {
        vi.mocked(http.get).mockResolvedValue({
            data: {
                object: 'extension_flags',
                attributes: {
                    active: ['assistant'],
                    flags: { assistant: { 'agent-ready': true } },
                },
            },
        });

        await refreshExtensionFlags();

        expect(http.get).toHaveBeenCalledWith('/api/client/extensions/flags');
        expect(useFlags.getState().everest?.extensions).toEqual({
            enabled: true,
            active: ['assistant'],
            flags: { assistant: { 'agent-ready': true } },
        });
    });
});
