import { useEffect, useRef, useState } from 'react';
import { ExtensionData, getExtensions } from '@/api/routes/admin/extensions';
import ExtensionCard from './ExtensionCard';
import Spinner from '@/elements/Spinner';
import useFlash from '@/plugins/useFlash';

export default () => {
    const [extensions, setExtensions] = useState<ExtensionData[]>([]);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError } = useFlash();
    const containerRef = useRef<HTMLDivElement | null>(null);

    const fetchExtensions = () => {
        setLoading(true);
        getExtensions()
            .then(data => setExtensions(data))
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        fetchExtensions();
    }, []);

    useEffect(() => {
        const container = containerRef.current;
        if (!container || extensions.length === 0) {
            return;
        }

        const updateHeights = () => {
            const cards = Array.from(container.querySelectorAll<HTMLElement>('[data-extension-card]'));
            const maxHeight = cards.reduce((max, card) => Math.max(max, card.offsetHeight), 0);
            if (maxHeight > 0) {
                container.style.setProperty('--extension-card-min-height', `${maxHeight}px`);
            }
        };

        const scheduleUpdate = () => {
            requestAnimationFrame(updateHeights);
        };

        const resizeObserver = new ResizeObserver(scheduleUpdate);
        const cards = Array.from(container.querySelectorAll<HTMLElement>('[data-extension-card]'));
        cards.forEach(card => resizeObserver.observe(card));

        scheduleUpdate();

        return () => {
            resizeObserver.disconnect();
        };
    }, [extensions]);

    if (loading) {
        return (
            <div className={'flex items-center justify-center py-16'}>
                <Spinner size={'large'} />
            </div>
        );
    }

    if (extensions.length === 0) {
        return (
            <div className={'rounded-lg bg-zinc-800 p-8 text-center'}>
                <p className={'text-neutral-400'}>No extensions are available.</p>
                <p className={'mt-2 text-sm text-neutral-500'}>
                    Extensions can be added through the configuration files.
                </p>
            </div>
        );
    }

    return (
        <div ref={containerRef} className={'grid gap-6 sm:grid-cols-2 lg:grid-cols-3'}>
            {extensions.map((extension: ExtensionData) => (
                <ExtensionCard key={extension.id} extension={extension} onUpdate={fetchExtensions} />
            ))}
        </div>
    );
};
