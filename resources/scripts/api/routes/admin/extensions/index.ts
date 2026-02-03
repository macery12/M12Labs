import http from '@/api/http';

export interface ExtensionData {
    id: string;
    name: string;
    description: string;
    version: string;
    author: string;
    icon: string;
    enabled: boolean;
    allowedNests: number[];
    allowedEggs: number[];
    settings: Record<string, unknown>;
}

export interface NestOption {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
}

export interface EggOption {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
    nestId: number;
    nestName: string;
}

export interface NestsAndEggs {
    nests: NestOption[];
    eggs: EggOption[];
}

export const getExtensions = async (): Promise<ExtensionData[]> => {
    const { data } = await http.get('/api/application/extensions');
    return data.data;
};

export const getExtension = async (extensionId: string): Promise<ExtensionData> => {
    const { data } = await http.get(`/api/application/extensions/${extensionId}`);
    return data;
};

export const updateExtension = async (
    extensionId: string,
    allowedNests: number[],
    allowedEggs: number[]
): Promise<ExtensionData> => {
    const { data } = await http.put(`/api/application/extensions/${extensionId}`, {
        allowed_nests: allowedNests,
        allowed_eggs: allowedEggs,
    });
    return data;
};

export const toggleExtension = async (extensionId: string): Promise<ExtensionData> => {
    const { data } = await http.post(`/api/application/extensions/${extensionId}/toggle`);
    return data;
};

export const updateModuleSettings = async (enabled: boolean): Promise<void> => {
    await http.put('/api/application/extensions/settings', { key: 'enabled', value: enabled });
};

export const getNestsAndEggs = async (): Promise<NestsAndEggs> => {
    const { data } = await http.get('/api/application/extensions/nests-eggs');
    return data;
};
