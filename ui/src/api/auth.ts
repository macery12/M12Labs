import http, { primeCsrf } from '@/lib/http';

export interface AuthResponse {
    complete: boolean;
    intended?: string;
    confirmationToken?: string;
}

// POST /auth/login — same contract as V1 (api/routes/auth/login.ts).
// `user` accepts username or email. Response is wrapped as { data: {...} }.
export async function login(params: {
    user: string;
    password: string;
    captchaToken?: string;
}): Promise<AuthResponse> {
    await primeCsrf();
    const { data } = await http.post('/auth/login', {
        user: params.user,
        password: params.password,
        'cf-turnstile-response': params.captchaToken,
    });
    return {
        complete: data.data.complete,
        intended: data.data.intended || undefined,
        confirmationToken: data.data.confirmation_token || undefined,
    };
}

// POST /auth/login/checkpoint — TOTP / recovery 2FA step.
export async function checkpoint(params: {
    confirmationToken: string;
    code: string;
    recoveryToken?: string;
}): Promise<AuthResponse> {
    const { data } = await http.post('/auth/login/checkpoint', {
        confirmation_token: params.confirmationToken,
        authentication_code: params.code,
        recovery_token: params.recoveryToken && params.recoveryToken.length > 0 ? params.recoveryToken : undefined,
    });
    return { complete: data.data.complete, intended: data.data.intended || undefined };
}
