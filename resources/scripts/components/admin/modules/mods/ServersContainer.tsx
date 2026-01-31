import { useState, useEffect } from 'react';
import tw from 'twin.macro';
import AdminBox from '@/elements/AdminBox';
import { faServer } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import { toggleServerMods } from '@/api/routes/admin/server';
import http from '@/api/http';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import { useStoreState } from '@/state/hooks';

interface Server {
    id: number;
    uuid: string;
    name: string;
    modsEnabled: boolean;
}

type FilterOption = 'all' | 'enabled' | 'disabled';

export default () => {
    const { clearFlashes, addError, addFlash } = useFlash();
    const [servers, setServers] = useState<Server[]>([]);
    const [loading, setLoading] = useState(true);
    const [toggling, setToggling] = useState<number | null>(null);
    const [filter, setFilter] = useState<FilterOption>('all');
    const { background } = useStoreState(state => state.theme.data!.colors);

    useEffect(() => {
        setLoading(true);
        http.get('/api/application/servers')
            .then(({ data }) => {
                const serverList = data.data.map((item: any) => ({
                    id: item.attributes.id,
                    uuid: item.attributes.uuid,
                    name: item.attributes.name,
                    modsEnabled: item.attributes.mods_enabled || false,
                }));
                setServers(serverList);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'mods:servers', message: 'Failed to load servers.' });
            })
            .finally(() => setLoading(false));
    }, []);

    const handleToggle = (serverId: number, currentState: boolean) => {
        clearFlashes('mods:servers');
        setToggling(serverId);

        toggleServerMods(serverId, !currentState)
            .then(() => {
                setServers(prev => prev.map(s => (s.id === serverId ? { ...s, modsEnabled: !currentState } : s)));
                addFlash({
                    key: 'mods:servers',
                    type: 'success',
                    message: `Mods ${!currentState ? 'enabled' : 'disabled'} for server successfully.`,
                });
            })
            .catch(error => {
                console.error(error);
                addError({
                    key: 'mods:servers',
                    message: 'Failed to toggle mods for server.',
                });
            })
            .finally(() => setToggling(null));
    };

    if (loading) {
        return (
            <AdminBox title={'Server Mods Management'} icon={faServer}>
                <div css={tw`flex justify-center py-8`}>
                    <Spinner size={'large'} />
                </div>
            </AdminBox>
        );
    }

    if (servers.length === 0) {
        return (
            <AdminBox title={'Server Mods Management'} icon={faServer}>
                <div css={tw`text-center py-8`}>
                    <p css={tw`text-neutral-400`}>No servers found.</p>
                </div>
            </AdminBox>
        );
    }

    const filteredServers = servers.filter(server => {
        if (filter === 'enabled') return server.modsEnabled;
        if (filter === 'disabled') return !server.modsEnabled;
        return true;
    });

    return (
        <AdminBox title={'Server Mods Management'} icon={faServer}>
            <div css={tw`mb-4`}>
                <p css={tw`text-sm text-neutral-400 mb-3`}>
                    Enable or disable the mods feature for individual servers. When enabled, users can browse and
                    download Minecraft mods from CurseForge for that server.
                </p>
                <div css={tw`flex gap-2`}>
                    <Button.Text
                        size={Button.Sizes.Small}
                        onClick={() => setFilter('all')}
                        css={[filter === 'all' && tw`bg-primary-500/20 text-primary-400`]}
                    >
                        All ({servers.length})
                    </Button.Text>
                    <Button.Text
                        size={Button.Sizes.Small}
                        onClick={() => setFilter('enabled')}
                        css={[filter === 'enabled' && tw`bg-primary-500/20 text-primary-400`]}
                    >
                        Enabled ({servers.filter(s => s.modsEnabled).length})
                    </Button.Text>
                    <Button.Text
                        size={Button.Sizes.Small}
                        onClick={() => setFilter('disabled')}
                        css={[filter === 'disabled' && tw`bg-primary-500/20 text-primary-400`]}
                    >
                        Disabled ({servers.filter(s => !s.modsEnabled).length})
                    </Button.Text>
                </div>
            </div>
            <div css={tw`space-y-2`}>
                {filteredServers.length === 0 ? (
                    <div css={tw`text-center py-8`}>
                        <p css={tw`text-neutral-400`}>No servers match the selected filter.</p>
                    </div>
                ) : (
                    filteredServers.map(server => (
                        <div
                            key={server.id}
                            css={tw`flex items-center justify-between p-3 rounded border border-neutral-600`}
                            style={{ backgroundColor: background }}
                        >
                            <div css={tw`flex-1`}>
                                <p css={tw`text-sm font-medium text-neutral-100`}>{server.name}</p>
                                <p css={tw`text-xs text-neutral-400`}>UUID: {server.uuid}</p>
                            </div>
                            <div css={tw`flex items-center gap-3`}>
                                <span
                                    css={[
                                        tw`text-xs font-medium px-2 py-1 rounded`,
                                        server.modsEnabled
                                            ? tw`bg-green-500/20 text-green-400`
                                            : tw`bg-neutral-600 text-neutral-400`,
                                    ]}
                                >
                                    {server.modsEnabled ? 'Enabled' : 'Disabled'}
                                </span>
                                {server.modsEnabled ? (
                                    <Button.Danger
                                        size={Button.Sizes.Small}
                                        onClick={() => handleToggle(server.id, server.modsEnabled)}
                                        disabled={toggling === server.id}
                                    >
                                        {toggling === server.id ? 'Disabling...' : 'Disable'}
                                    </Button.Danger>
                                ) : (
                                    <Button
                                        size={Button.Sizes.Small}
                                        onClick={() => handleToggle(server.id, server.modsEnabled)}
                                        disabled={toggling === server.id}
                                    >
                                        {toggling === server.id ? 'Enabling...' : 'Enable'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </AdminBox>
    );
};
