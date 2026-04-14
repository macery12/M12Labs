import { useEffect, useState } from 'react';
import http from '@/api/http';
import Spinner from '@/elements/Spinner';
import PageContentBlock from '@/elements/PageContentBlock';
import { ServerContext } from '@/state/server';

interface ServerInfoResponse {
    name: string;
    node: string;
    egg: string;
    serverOwner: boolean;
    panel: {
        name: string;
        version: string;
    };
}

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const [data, setData] = useState<ServerInfoResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!uuid) {
            return;
        }

        setLoading(true);
        setError(null);

        http.get(`/api/client/servers/${uuid}/extensions/server_info`)
            .then(response => setData(response.data.attributes))
            .catch(() => setError('Unable to load extension details for this server.'))
            .finally(() => setLoading(false));
    }, [uuid]);

    if (loading) {
        return (
            <PageContentBlock title={'Server Info'}>
                <div className={'flex items-center justify-center py-16'}>
                    <Spinner size={'large'} />
                </div>
            </PageContentBlock>
        );
    }

    if (error || !data) {
        return (
            <PageContentBlock title={'Server Info'}>
                <div className={'rounded-lg bg-zinc-800 p-6 text-sm text-red-200'}>{error ?? 'Unknown error.'}</div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'Server Info'}>
            <div className={'grid gap-4 md:grid-cols-2'}>
                <div className={'rounded-lg bg-zinc-800 p-6'}>
                    <p className={'text-xs uppercase tracking-wide text-neutral-500'}>Server</p>
                    <h2 className={'mt-2 text-xl font-semibold text-white'}>{data.name}</h2>
                    <p className={'mt-4 text-sm text-neutral-400'}>Egg: {data.egg}</p>
                    <p className={'mt-1 text-sm text-neutral-400'}>Node: {data.node}</p>
                </div>
                <div className={'rounded-lg bg-zinc-800 p-6'}>
                    <p className={'text-xs uppercase tracking-wide text-neutral-500'}>Panel</p>
                    <h2 className={'mt-2 text-xl font-semibold text-white'}>{data.panel.name}</h2>
                    <p className={'mt-4 text-sm text-neutral-400'}>Version: {data.panel.version}</p>
                    <p className={'mt-1 text-sm text-neutral-400'}>
                        You are {data.serverOwner ? 'the server owner' : 'a delegated user'} on this server.
                    </p>
                </div>
            </div>
        </PageContentBlock>
    );
};