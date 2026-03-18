import { ThemeColorMap, ThemeTokens, deriveTokensFromColors, fallbackColors } from '@/theme/tokens';

export interface ThemePreset {
    id: string;
    name: string;
    description?: string;
    colors: Partial<ThemeColorMap>;
    tokens?: Partial<ThemeTokens>;
}

const withTokens = (colors: Partial<ThemeColorMap>) => {
    const merged = { ...fallbackColors, ...colors };
    return {
        colors,
        tokens: deriveTokensFromColors(merged),
    };
};

export const themePresets: ThemePreset[] = [
    { id: 'jexactyl-green', name: 'Jexactyl Green', ...withTokens({ primary: '#16a34a' }) },
    { id: 'microsoft-teal', name: 'Microsoft Teal', ...withTokens({ primary: '#12aaaa' }) },
    { id: 'brick-red', name: 'Brick Red', ...withTokens({ primary: '#ff0000' }) },
    { id: 'iris-purple', name: 'Iris Purple', ...withTokens({ primary: '#9D00FF' }) },
    { id: 'orange-orange', name: 'Orange Orange', ...withTokens({ primary: '#FFA500' }) },
    { id: 'ptero-blue', name: 'Ptero Blue', ...withTokens({ primary: '#32559f' }) },
    { id: 'pretty-pink', name: 'Pretty Pink', ...withTokens({ primary: '#ff99c8' }) },
    { id: 'plain-grey', name: 'Plain Grey', ...withTokens({ primary: '#5e6472' }) },
];
