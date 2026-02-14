import http from '@/api/http';

export interface DiscordLinkStatus {
    linked: boolean;
    discord_id?: string;
    discord_username?: string;
    discord_avatar?: string;
}

export const getDiscordLinkStatus = async (): Promise<DiscordLinkStatus> => {
    const { data } = await http.get('/api/client/account/discord/status');
    return data;
};

export const getDiscordLinkUrl = async (): Promise<string> => {
    const { data } = await http.get('/api/client/account/discord/link-url');
    return data.url;
};

export const unlinkDiscord = async (): Promise<void> => {
    await http.post('/api/client/account/discord/unlink');
};
