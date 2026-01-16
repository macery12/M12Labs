import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import ModpackSearch from './ModpackSearch';
import ModpackList from './ModpackList';
import ModpackDetails from './ModpackDetails';
import ModpackStatus from './ModpackStatus';
import ModpackInstallProgress from './ModpackInstallProgress';
import {
    type CurseForgeModpack,
    type ModpackSearchParams,
    searchModpacks,
    getInstalledModpack,
    type InstalledModpackInfo,
} from '@/api/routes/server/modpacks';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const modsEnabled = ServerContext.useStoreState(state => state.server.data!.modsEnabled);
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const { addError } = useFlash();

    const [loading, setLoading] = useState(false);
    const [modpacks, setModpacks] = useState<CurseForgeModpack[]>([]);
    const [selectedModpack, setSelectedModpack] = useState<CurseForgeModpack | null>(null);
    const [installedModpack, setInstalledModpack] = useState<InstalledModpackInfo | null>(null);
    const [checkingInstalled, setCheckingInstalled] = useState(true);
    const [installing, setInstalling] = useState(false);
    const [pagination, setPagination] = useState({
        index: 0,
        pageSize: 20,
        resultCount: 0,
        totalCount: 0,
    });

    const [searchParams, setSearchParams] = useState<ModpackSearchParams>({
        searchFilter: '',
        sortField: '2',
        sortOrder: 'desc',
        gameVersion: undefined,
        modLoaderType: undefined,
        pageSize: 20,
        index: 0,
    });

    const refreshInstalledModpack = () => {
        setCheckingInstalled(true);
        getInstalledModpack(uuid)
            .then(result => {
                setInstalledModpack(result.installed ? result : null);
            })
            .catch(error => {
                console.error(error);
                // If there's an error, just assume no modpack is installed
                setInstalledModpack(null);
            })
            .finally(() => setCheckingInstalled(false));
    };

    useEffect(() => {
        if (!modsEnabled) return;

        // Check for installed modpack first
        refreshInstalledModpack();
    }, [uuid, modsEnabled]);

    useEffect(() => {
        if (!modsEnabled || installedModpack?.installed) return;

        setLoading(true);
        searchModpacks(uuid, searchParams)
            .then(response => {
                setModpacks(response.data);
                setPagination(response.pagination);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [uuid, searchParams, modsEnabled, installedModpack]);

    const handleSearch = (params: ModpackSearchParams) => {
        setSelectedModpack(null); // Close modal when searching
        setSearchParams({ ...params, index: 0 });
    };

    const handlePageChange = (newIndex: number) => {
        setSelectedModpack(null); // Close modal when changing pages
        setSearchParams({ ...searchParams, index: newIndex });
    };

    const handleModpackClick = (modpack: CurseForgeModpack) => {
        setSelectedModpack(modpack);
    };

    const handleSwapModpack = () => {
        setInstalledModpack(null);
        // Reset search to trigger new search
        setSearchParams({ ...searchParams, index: 0 });
    };

    const handleDownloadStart = () => {
        // When download starts, show the installing progress view
        setInstalling(true);
        setSelectedModpack(null); // Close the modal
    };

    const handleDownloadComplete = () => {
        // Refresh installed modpack status
        setInstalling(false);
        refreshInstalledModpack();
    };

    if (!globalModsEnabled) {
        return (
            <PageContentBlock title={'Modpacks Browser'} header description={'Browse and install Minecraft modpacks.'}>
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>The Mods module is not enabled.</p>
                    <p css={tw`text-neutral-400 text-sm`}>
                        Contact your panel administrator to enable the Mods module in the admin area.
                    </p>
                </div>
            </PageContentBlock>
        );
    }

    if (!modsEnabled) {
        return (
            <PageContentBlock title={'Modpacks Browser'} header description={'Browse and install Minecraft modpacks.'}>
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>Mods are not enabled for this server.</p>
                    <p css={tw`text-neutral-400 text-sm mb-2`}>
                        An administrator needs to enable the mods feature for this specific server.
                    </p>
                    <p css={tw`text-neutral-400 text-xs`}>
                        This can be done in the admin panel under Servers → [Server Name] → Mods Toggle.
                    </p>
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock
            title={'Modpacks Browser'}
            header
            description={'Browse and install Minecraft modpacks from CurseForge.'}
            showFlashKey={'modpacks'}
        >
            {checkingInstalled ? (
                <div css={tw`mt-8`}>
                    <Spinner size={'large'} centered />
                </div>
            ) : installing ? (
                <ModpackInstallProgress onComplete={handleDownloadComplete} />
            ) : installedModpack?.installed ? (
                <ModpackStatus installedModpack={installedModpack} onSwapModpack={handleSwapModpack} />
            ) : (
                <>
                    <ModpackSearch onSearch={handleSearch} initialParams={searchParams} />

                    {loading && !modpacks.length ? (
                        <div css={tw`mt-8`}>
                            <Spinner size={'large'} centered />
                        </div>
                    ) : (
                        <ModpackList
                            modpacks={modpacks}
                            loading={loading}
                            pagination={pagination}
                            onModpackClick={handleModpackClick}
                            onPageChange={handlePageChange}
                        />
                    )}

                    {selectedModpack && (
                        <ModpackDetails
                            key={selectedModpack.id}
                            modpack={selectedModpack}
                            onClose={() => setSelectedModpack(null)}
                            onDownloadStart={handleDownloadStart}
                            onDownloadComplete={handleDownloadComplete}
                        />
                    )}
                </>
            )}
        </PageContentBlock>
    );
};
