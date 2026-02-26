import { action, Action } from 'easy-peasy';

export type AlertType = 'success' | 'warning' | 'danger' | 'info';
export type AlertPosition = 'notification' | 'top-center' | 'slide-out' | 'center';

export type VerificationArea = 'billing' | 'orders' | 'donate' | 'credentials' | 'tickets';
export type VerificationRule = { can_view: boolean; can_interact: boolean };
export type VerificationRules = Record<VerificationArea, VerificationRule>;

export interface EverestSettings {
    auth: {
        registration: {
            enabled: boolean;
        };
        security: {
            force2fa: boolean;
            attempts: number;
        };
        modules: {
            jguard: {
                enabled: boolean;
                delay?: number;
            };
            discord: {
                enabled: boolean;
                clientId: boolean;
                clientSecret: boolean;
            };
            google: {
                enabled: boolean;
                clientId: boolean;
                clientSecret: boolean;
            };
            onboarding: {
                enabled: boolean;
                content?: string;
            };
        };
    };
    tickets: {
        enabled: boolean;
        maxCount: number;
    };
    billing: {
        enabled: boolean;
        donations_enabled: boolean;
        paypal: boolean;
        link: boolean;
        processor?: string;
        processors?: {
            stripe: {
                available: boolean;
                enabled: boolean;
            };
            mollie: {
                available: boolean;
                enabled: boolean;
            };
            paypal: {
                available: boolean;
                enabled: boolean;
            };
        };
        keys: {
            publishable: boolean;
            secret: boolean;
        };
        mollie?: {
            api_key?: boolean;
        };
        paypal_standalone?: {
            mode?: string;
        };
        integrations?: {
            [key: string]: {
                enabled: boolean;
            };
        };
        currency: {
            symbol: string;
            code: string;
        };
        links: {
            terms: string;
            privacy: string;
        };
        renewal?: {
            days: number;
            free_renewal_days: number;
            suspension_threshold: number;
            free_suspension_days: number;
            paid_suspension_days: number;
            default_billing_days: number;
            multiplier_steps: string;
        };
        plan_change_cooldown_hours?: number;
    };
    alert: {
        enabled: boolean;
        type: AlertType;
        position: AlertPosition;
        content: string;
        uuid: string;
    };
    ai: {
        enabled: boolean;
        key: boolean | string;
        user_access: boolean;
        endpoint?: string;
        model?: string;
        mode?: string;
        max_tokens?: number;
        system_prompt?: string;
    };
    mods: {
        enabled: boolean;
        curseforge_api_key: boolean | string;
        default_source?: string;
        spiget_enabled?: boolean;
    };
    webhooks: {
        enabled: boolean;
        url: boolean;
    };
    email: {
        enabled?: boolean;
        resend: {
            enabled?: boolean;
            api_key: boolean;
            from_email: string;
            from_name: string;
            reply_to: string;
        } | boolean;
        verification_rules?: VerificationRules;
    };
}

export interface EverestStore {
    data?: EverestSettings;
    setEverest: Action<EverestStore, EverestSettings>;
    updateEverest: Action<EverestStore, Partial<EverestSettings>>;
}

const everest: EverestStore = {
    data: undefined,

    setEverest: action((state, payload) => {
        state.data = payload;
    }),

    updateEverest: action((state, payload) => {
        // @ts-expect-error limitation of Typescript, can't do much about that currently unfortunately.
        state.data = { ...state.data, ...payload };
    }),
};

export default everest;
