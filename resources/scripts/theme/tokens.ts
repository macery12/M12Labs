import { SiteTheme } from '@/state/theme';

export type ThemeColorMap = SiteTheme['colors'];

export interface ThemeTokens {
    base: {
        background: string;
        foreground: string;
        muted: string;
        border: string;
    };
    surfaces: {
        panel: string;
        raised: string;
        header: string;
        overlay: string;
    };
    navigation: {
        sidebar: string;
        sidebarActive: string;
        navbar: string;
        navbarBorder: string;
    };
    text: {
        primary: string;
        secondary: string;
        muted: string;
        inverse: string;
        onAccent: string;
    };
    status: {
        success: string;
        warning: string;
        danger: string;
        info: string;
    };
    inputs: {
        background: string;
        surface: string;
        border: string;
        focus: string;
        text: string;
        placeholder: string;
    };
    interactive: {
        accent: string;
        accentMuted: string;
        accentHover: string;
        selection: string;
    };
    borders: {
        subtle: string;
        strong: string;
    };
}

export const fallbackColors: ThemeColorMap = {
    primary: '#16a34a',
    secondary: '#27272a',
    background: '#141414',
    headers: '#171717',
    sidebar: '#18181b',
};

const statusSuccess = '#22c55e';

const tokenFallbacks: ThemeTokens = {
    base: {
        background: fallbackColors.background,
        foreground: '#f8fafc',
        muted: '#a1a1aa',
        border: '#27272a',
    },
    surfaces: {
        panel: fallbackColors.secondary,
        raised: '#1f1f22',
        header: fallbackColors.headers,
        overlay: 'rgba(0,0,0,0.65)',
    },
    navigation: {
        sidebar: fallbackColors.sidebar,
        sidebarActive: fallbackColors.primary,
        navbar: fallbackColors.headers,
        navbarBorder: fallbackColors.primary,
    },
    text: {
        primary: '#f8fafc',
        secondary: '#e5e7eb',
        muted: '#a1a1aa',
        inverse: '#0f172a',
        onAccent: '#0b0f12',
    },
    status: {
        success: statusSuccess,
        warning: '#f59e0b',
        danger: '#ef4444',
        info: '#38bdf8',
    },
    inputs: {
        background: fallbackColors.background,
        surface: fallbackColors.headers,
        border: '#27272a',
        focus: fallbackColors.primary,
        text: '#f8fafc',
        placeholder: '#9ca3af',
    },
    interactive: {
        accent: fallbackColors.primary,
        accentMuted: '#14532d',
        accentHover: statusSuccess,
        selection: 'rgba(34,197,94,0.25)',
    },
    borders: {
        subtle: '#1f2937',
        strong: '#374151',
    },
};

const mergeDeep = <T extends Record<string, unknown>>(target: T, source: Partial<T>): T => {
    const output = { ...target } as T;

    Object.keys(source || {}).forEach(key => {
        const value = (source as Record<string, unknown>)[key];

        if (value && typeof value === 'object' && !Array.isArray(value)) {
            (output as Record<string, unknown>)[key] = mergeDeep(
                ((target as Record<string, unknown>)[key] as Record<string, unknown>) || {},
                value as Record<string, unknown>,
            );
        } else if (typeof value !== 'undefined') {
            (output as Record<string, unknown>)[key] = value;
        }
    });

    return output;
};

export const deriveTokensFromColors = (colors: ThemeColorMap): ThemeTokens => {
    return mergeDeep(tokenFallbacks, {
        base: {
            background: colors.background ?? fallbackColors.background,
        },
        surfaces: {
            panel: colors.secondary ?? fallbackColors.secondary,
            header: colors.headers ?? fallbackColors.headers,
        },
        navigation: {
            sidebar: colors.sidebar ?? fallbackColors.sidebar,
            sidebarActive: colors.primary ?? fallbackColors.primary,
            navbar: colors.headers ?? fallbackColors.headers,
            navbarBorder: colors.primary ?? fallbackColors.primary,
        },
        inputs: {
            background: colors.background ?? fallbackColors.background,
            surface: colors.headers ?? fallbackColors.headers,
            focus: colors.primary ?? fallbackColors.primary,
        },
        interactive: {
            accent: colors.primary ?? fallbackColors.primary,
        },
    });
};

export const normalizeTheme = (theme?: Partial<SiteTheme>): SiteTheme => {
    const colors: ThemeColorMap = {
        ...fallbackColors,
        ...(theme?.colors || {}),
    };

    const derivedTokens = deriveTokensFromColors(colors);
    const tokens = mergeDeep(derivedTokens, (theme?.tokens as Partial<ThemeTokens>) || {});

    return {
        ...(theme as SiteTheme),
        colors,
        tokens,
    };
};

type Flattened = Record<string, string>;

export const flattenTokens = (tokens: ThemeTokens): Flattened => {
    const result: Flattened = {};

    const flattenObject = (obj: Record<string, unknown>, prefix: string[] = []) => {
        Object.entries(obj).forEach(([key, value]) => {
            const path = [...prefix, key];

            if (value && typeof value === 'object' && !Array.isArray(value)) {
                flattenObject(value as Record<string, unknown>, path);
            } else if (typeof value !== 'undefined') {
                result[path.join('.')] = String(value);
            }
        });
    };

    flattenObject(tokens as Record<string, unknown>);

    return result;
};

export const tokensToLegacyColors = (tokens: ThemeTokens): ThemeColorMap => ({
    primary: tokens.interactive.accent,
    secondary: tokens.surfaces.panel,
    background: tokens.base.background,
    headers: tokens.surfaces.header,
    sidebar: tokens.navigation.sidebar,
});

export const getTokenFallbacks = (): ThemeTokens => tokenFallbacks;
