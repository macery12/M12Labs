import http from '@/api/http';

export interface DiscordRegistrationData {
    discord_username: string;
    discord_email: string;
    discord_id: string;
}

export interface CompleteDiscordRegistrationData {
    username: string;
    password: string;
    confirm_password: string;
}

export interface UsernameCheckResponse {
    available: boolean;
    message: string;
}

export const getDiscordRegistrationData = (): Promise<DiscordRegistrationData> => {
    return new Promise((resolve, reject) => {
        http.get('/auth/modules/discord/registration-data')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const checkUsernameAvailability = (username: string): Promise<UsernameCheckResponse> => {
    return new Promise((resolve, reject) => {
        http.post('/auth/modules/discord/check-username', { username })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const completeDiscordRegistration = (data: CompleteDiscordRegistrationData): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.get('/sanctum/csrf-cookie')
            .then(() =>
                http.post('/auth/modules/discord/complete', {
                    username: data.username,
                    password: data.password,
                    confirm_password: data.confirm_password,
                }),
            )
            .then(() => resolve())
            .catch(reject);
    });
};
