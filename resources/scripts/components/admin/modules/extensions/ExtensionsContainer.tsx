import { useEffect, useState } from 'react';
import { ExtensionData, getExtensions } from '@/api/routes/admin/extensions';
import ExtensionCard from './ExtensionCard';
import Spinner from '@/elements/Spinner';
import useFlash from '@/plugins/useFlash';

export default () => {
    const [extensions, setExtensions] = useState<ExtensionData[]>([]);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError } = useFlash();

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

    if (loading) {
        return (
            <div className={'flex items-center justify-center py-16'}>
                <Spinner size={'large'} />
            </div>
        );
    }

    if (extensions.length === 0) {
        return (
            <div className={'rounded-lg bg-neutral-800 p-8 text-center'}>
                <p className={'text-neutral-400'}>No extensions are available.</p>
                <p className={'mt-2 text-sm text-neutral-500'}>
                    Extensions can be added through the configuration files.
                </p>
            </div>
        );
    }

    return (
        <div className={'grid gap-6 sm:grid-cols-2 lg:grid-cols-3'}>
            {extensions.map(extension => (
                <ExtensionCard key={extension.id} extension={extension} onUpdate={fetchExtensions} />
            ))}
        </div>
    );
};
