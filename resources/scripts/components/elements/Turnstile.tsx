import { useEffect, useRef } from 'react';

interface TurnstileProps {
    siteKey: string;
    onVerify: (token: string) => void;
    onError?: () => void;
    onExpire?: () => void;
}

interface TurnstileRenderOptions {
    sitekey: string;
    callback: (token: string) => void;
    'error-callback'?: () => void;
    'expired-callback'?: () => void;
}

declare global {
    interface Window {
        turnstile?: {
            render: (element: HTMLElement, options: TurnstileRenderOptions) => string;
            reset: (widgetId: string) => void;
            remove: (widgetId: string) => void;
        };
        onTurnstileLoad?: () => void;
    }
}

export default function Turnstile({ siteKey, onVerify, onError, onExpire }: TurnstileProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);

    // Store callbacks in refs to avoid re-rendering when they change
    const onVerifyRef = useRef(onVerify);
    const onErrorRef = useRef(onError);
    const onExpireRef = useRef(onExpire);

    // Update refs when callbacks change
    useEffect(() => {
        onVerifyRef.current = onVerify;
        onErrorRef.current = onError;
        onExpireRef.current = onExpire;
    });

    useEffect(() => {
        if (!containerRef.current || !siteKey) return;

        const initTurnstile = () => {
            if (window.turnstile && containerRef.current && !widgetId.current) {
                widgetId.current = window.turnstile.render(containerRef.current, {
                    sitekey: siteKey,
                    callback: (token: string) => onVerifyRef.current(token),
                    'error-callback': () => onErrorRef.current?.(),
                    'expired-callback': () => {
                        widgetId.current = null;
                        onExpireRef.current?.();
                    },
                });
            }
        };

        // If Turnstile is already loaded, initialize immediately
        if (window.turnstile) {
            initTurnstile();
        } else {
            // Otherwise, wait for the script to load
            window.onTurnstileLoad = initTurnstile;
        }

        return () => {
            if (widgetId.current && window.turnstile) {
                window.turnstile.remove(widgetId.current);
                widgetId.current = null;
            }
        };
    }, [siteKey]); // Only re-render when siteKey changes

    return <div ref={containerRef} />;
}
