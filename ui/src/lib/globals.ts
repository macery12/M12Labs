// Types for the window.* globals injected by the Laravel blade (see V1
// wrapper.blade.php + the *-bound view composers). These are the bootstrap
// contract the v2 UI reads — identical to what V1 consumes.

export interface PterodactylUser {
    uuid: string;
    username: string;
    email: string;
    root_admin: boolean;
    use_totp: boolean;
    language: string;
    avatar_url: string;
    admin_role_name: string;
    admin_role_id?: number;
    state: string;
    email_verified?: boolean;
    email_verified_at?: string | null;
    updated_at: string;
    created_at: string;
    discord_linked?: boolean;
}

export interface SiteConfiguration {
    name: string;
    logo: string | null;
    mode: string;
    setup: boolean;
    debug: boolean;
    locale: string;
    speed_dial: boolean;
    indicators: boolean;
    captcha: { enabled: boolean; siteKey: string };
    activity: { enabled: { account: boolean; server: boolean; admin: boolean } };
}

export interface ThemeConfiguration {
    colors: {
        primary?: string | null;
        canvas?: string | null;
        surface?: string | null;
        surface_2?: string | null;
        border?: string | null;
        ink?: string | null;
        ink_muted?: string | null;
        accent?: string | null;
        warning?: string | null;
        danger?: string | null;
    };
    feel?: {
        radius?: 'sharp' | 'soft' | 'round' | null;
        grid_enabled?: boolean | null;
        grid_opacity?: number | null;
        grid_size?: number | null;
        aurora_enabled?: boolean | null;
        aurora_intensity?: number | null;
    };
}

// Storefront/checkout billing config surfaced through the everest payload
// (mirrors V1's state/everest.ts `billing` block). Only the client-facing
// fields the V2 store/checkout reads are typed here.
export interface BillingConfig {
    enabled: boolean;
    processors?: {
        stripe: { available: boolean; enabled: boolean };
        paypal: { available: boolean; enabled: boolean };
    };
    currency: { symbol: string; code: string };
    links: { terms: string; privacy: string };
    require_billing_address?: boolean;
    [k: string]: unknown;
}

// Feature flags — kept loose; only the fields the registry conditions read are
// strongly used. Mirrors window.EverestConfiguration.
export interface EverestConfiguration {
    auth: {
        registration: { enabled: boolean };
        security: { force2fa: boolean };
        captcha: { provider: string; site_key: string };
        modules: {
            discord: { enabled: boolean };
            google: { enabled: boolean };
            [k: string]: unknown;
        };
    };
    tickets: { enabled: boolean; maxCount: number };
    billing: BillingConfig;
    ai: { enabled: boolean; feature_server_assistant: boolean; [k: string]: unknown };
    mods: { enabled: boolean; [k: string]: unknown };
    webhooks: { enabled: boolean; [k: string]: unknown };
    email: { enabled: boolean; [k: string]: unknown };
    extensions: { enabled: boolean };
    custom_domains: { enabled: boolean };
    [k: string]: unknown;
}

export interface FlashMessage {
    type: 'success' | 'error' | 'info' | 'warning';
    message: string;
}

declare global {
    interface Window {
        PterodactylUser?: PterodactylUser;
        SiteConfiguration?: SiteConfiguration;
        ThemeConfiguration?: ThemeConfiguration;
        EverestConfiguration?: EverestConfiguration;
        FlashMessages?: FlashMessage[];
    }
}

export const readCsrfToken = (): string =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
