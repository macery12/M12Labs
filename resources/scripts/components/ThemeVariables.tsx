import { useEffect, useMemo } from 'react';

import { flattenTokens, getTokenFallbacks, normalizeTheme } from '@/theme/tokens';
import { useStoreState } from '@/state/hooks';

const ThemeVariables = () => {
    const theme = useStoreState(state => state.theme.data);

    const normalized = useMemo(() => theme ?? normalizeTheme(), [theme]);

    useEffect(() => {
        const root = document.documentElement;

        const flattened = flattenTokens(normalized.tokens ?? getTokenFallbacks());

        Object.entries(flattened).forEach(([key, value]) => {
            root.style.setProperty(`--theme-${key.replace(/\./g, '-')}`, value);
        });

        root.style.setProperty('--theme-color-primary', normalized.colors.primary);
        root.style.setProperty('--theme-color-secondary', normalized.colors.secondary);
        root.style.setProperty('--theme-color-background', normalized.colors.background);
        root.style.setProperty('--theme-color-headers', normalized.colors.headers);
        root.style.setProperty('--theme-color-sidebar', normalized.colors.sidebar);
    }, [normalized]);

    return null;
};

export default ThemeVariables;
