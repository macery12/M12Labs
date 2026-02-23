import type { Stripe } from '@stripe/stripe-js';

let cachedStripePromise: Promise<Stripe | null> | null = null;
let cachedPublishableKey: string | null = null;

export const loadStripeOnce = async (publishableKey: string): Promise<Stripe | null> => {
    if (typeof window === 'undefined' || !publishableKey) return null;

    // Reuse the existing instance when the same key was already used.
    if (cachedStripePromise && cachedPublishableKey === publishableKey) {
        return cachedStripePromise;
    }

    // If Stripe.js is already on the page, avoid injecting a duplicate script.
    const existingScript = document.querySelector<HTMLScriptElement>('script[src^="https://js.stripe.com"]');
    if (existingScript && (window as Window & { Stripe?: (key: string) => Stripe }).Stripe) {
        cachedPublishableKey = publishableKey;
        cachedStripePromise = Promise.resolve(
            (window as Window & { Stripe?: (key: string) => Stripe }).Stripe?.(publishableKey) ?? null,
        );
        return cachedStripePromise;
    }

    const { loadStripe } = await import('@stripe/stripe-js');
    cachedPublishableKey = publishableKey;
    cachedStripePromise = loadStripe(publishableKey);

    return cachedStripePromise;
};
