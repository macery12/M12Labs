import { action, Action } from 'easy-peasy';

import { ThemeTokens, normalizeTheme } from '@/theme/tokens';

export interface SiteTheme {
    colors: {
        primary: string;
        secondary: string;

        background: string;
        headers: string;
        sidebar: string;
    };
    tokens?: ThemeTokens;
}

export interface ThemeStore {
    data?: SiteTheme;
    setTheme: Action<ThemeStore, SiteTheme>;
}

const theme: ThemeStore = {
    data: undefined,

    setTheme: action((state, payload) => {
        state.data = normalizeTheme(payload);
    }),
};

export default theme;
