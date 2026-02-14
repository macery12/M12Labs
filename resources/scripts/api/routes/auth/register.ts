import http from '@/api/http';

export interface LoginData {
    username: string;
    email: string;
    password: string;
    password_confirmation: string;
    recaptchaData?: string | null;
}

export interface UsernameCheckResponse {
    available: boolean;
    message: string;
}

export const checkUsernameAvailability = (username: string): Promise<UsernameCheckResponse> => {
    return new Promise((resolve, reject) => {
        http.post('/auth/check-username', { username })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export default ({ username, email, password, password_confirmation, recaptchaData }: LoginData): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.get('/sanctum/csrf-cookie')
            .then(() =>
                http.post('/auth/register', {
                    username,
                    email,
                    password,
                    password_confirmation,
                    'g-recaptcha-response': recaptchaData,
                }),
            )
            .then(() => resolve())
            .catch(reject);
    });
};
