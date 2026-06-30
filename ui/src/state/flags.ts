import { create } from 'zustand';
import type { EverestConfiguration, SiteConfiguration } from '@/lib/globals';

interface FlagsState {
    everest: EverestConfiguration | null;
    site: SiteConfiguration | null;
    set: (everest: EverestConfiguration | null, site: SiteConfiguration | null) => void;
}

export const useFlags = create<FlagsState>(set => ({
    everest: null,
    site: null,
    set: (everest, site) => set({ everest, site }),
}));
