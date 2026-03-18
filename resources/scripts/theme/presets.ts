import { ThemeColorMap } from '@/theme/tokens';

export interface ThemePreset {
    id: string;
    name: string;
    description?: string;
    colors: Partial<ThemeColorMap>;
}

export const themePresets: ThemePreset[] = [
    { id: 'jexactyl-green', name: 'Jexactyl Green', colors: { primary: '#16a34a' } },
    { id: 'microsoft-teal', name: 'Microsoft Teal', colors: { primary: '#12aaaa' } },
    { id: 'brick-red', name: 'Brick Red', colors: { primary: '#ff0000' } },
    { id: 'iris-purple', name: 'Iris Purple', colors: { primary: '#9D00FF' } },
    { id: 'orange-orange', name: 'Orange Orange', colors: { primary: '#FFA500' } },
    { id: 'ptero-blue', name: 'Ptero Blue', colors: { primary: '#32559f' } },
    { id: 'pretty-pink', name: 'Pretty Pink', colors: { primary: '#ff99c8' } },
    { id: 'plain-grey', name: 'Plain Grey', colors: { primary: '#5e6472' } },
];
