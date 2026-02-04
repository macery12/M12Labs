import http from '@/api/http';

export interface OnlinePlayer {
    name: string;
    uuid?: string;
}

export interface ServerStatus {
    online: boolean;
    players: {
        online: number;
        max: number;
        list: OnlinePlayer[];
    };
    version: string;
    motd: string;
}

export interface PlayerEntry {
    uuid: string;
    name: string;
    level?: number;
    bypassesPlayerLimit?: boolean;
    source?: string;
    created?: string;
    reason?: string;
    expires?: string;
}

export interface PlayerManagerStatus {
    server: ServerStatus;
    operators: PlayerEntry[];
    whitelist: PlayerEntry[];
    bannedPlayers: PlayerEntry[];
    bannedIps: { ip: string; reason: string; created: string; source: string; expires: string | null }[];
    whitelistEnabled: boolean;
}

export const getPlayerManagerStatus = async (uuid: string): Promise<PlayerManagerStatus> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/extensions/player-manager`);
    // Handle case where API returns nested data structure
    if (data && data.data) {
        return data.data;
    }
    return data;
};

export const setWhitelistEnabled = async (uuid: string, enabled: boolean): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/extensions/player-manager/whitelist`, { enabled });
};

export const addToWhitelist = async (uuid: string, player: string): Promise<void> => {
    await http.put(`/api/client/servers/${uuid}/extensions/player-manager/whitelist/${player}`);
};

export const removeFromWhitelist = async (uuid: string, player: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/extensions/player-manager/whitelist/${player}`);
};

export const opPlayer = async (uuid: string, player: string): Promise<void> => {
    await http.put(`/api/client/servers/${uuid}/extensions/player-manager/op/${player}`);
};

export const deopPlayer = async (uuid: string, player: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/extensions/player-manager/op/${player}`);
};

export const banPlayer = async (uuid: string, player: string, reason: string): Promise<void> => {
    await http.put(`/api/client/servers/${uuid}/extensions/player-manager/ban/${player}`, { reason });
};

export const unbanPlayer = async (uuid: string, player: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/extensions/player-manager/ban/${player}`);
};

export const banIp = async (uuid: string, ip: string, reason: string): Promise<void> => {
    await http.put(`/api/client/servers/${uuid}/extensions/player-manager/ban-ip/${ip}`, { reason });
};

export const unbanIp = async (uuid: string, ip: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/extensions/player-manager/ban-ip/${ip}`);
};

export const kickPlayer = async (uuid: string, player: string, reason?: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/extensions/player-manager/kick/${player}`, { reason });
};

export const whisperPlayer = async (uuid: string, player: string, message: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/extensions/player-manager/whisper/${player}`, { message });
};

export const killPlayer = async (uuid: string, player: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/extensions/player-manager/kill/${player}`);
};
