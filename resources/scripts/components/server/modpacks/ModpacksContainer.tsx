import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import ModpackSearch from './ModpackSearch';
import ModpackList from './ModpackList';
import ModpackDetails from './ModpackDetails';
import { type CurseForgeModpack, type ModpackSearchParams, searchModpacks } from '@/api/routes/server/modpacks';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';
import { getServerStartup } from '@/api/routes/server/startup';

// Environment variable names required for modpack support
const REQUIRED_MODPACK_VARIABLES = ['PROJECT_ID', 'VERSION_ID'] as const;

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const modsEnabled = ServerContext.useStoreState(state => state.server.data!.modsEnabled);
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const { addError } = useFlash();

    const [loading, setLoading] = useState(false);
    const [checkingSupport, setCheckingSupport] = useState(true);
    const [modpacksSupported, setModpacksSupported] = useState(false);
    const [modpacks, setModpacks] = useState<CurseForgeModpack[]>([]);
    const [selectedModpack, setSelectedModpack] = useState<CurseForgeModpack | null>(null);
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

    // Only fetch startup data if mods are enabled
    const shouldFetchStartup = modsEnabled && globalModsEnabled;
    const { data: startupData, error: startupError } = getServerStartup(
        uuid,
        shouldFetchStartup ? undefined : { invocation: '', variables: [], dockerImages: {} },
        { revalidateOnFocus: false, revalidateOnReconnect: false }
    );

    useEffect(() => {
        if (!shouldFetchStartup) {
            setCheckingSupport(false);
            setModpacksSupported(false);
            return;
        }

        if (startupData) {
            // Check if all required modpack variables exist
            const hasAllVariables = REQUIRED_MODPACK_VARIABLES.every(
                varName => startupData.variables.some(v => v.envVariable === varName)
            );
            setModpacksSupported(hasAllVariables);
            setCheckingSupport(false);
        } else if (startupError) {
            setModpacksSupported(false);
            setCheckingSupport(false);
        }
    }, [startupData, startupError, shouldFetchStartup]);

    useEffect(() => {
        if (!modsEnabled || !modpacksSupported) return;

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
    }, [uuid, searchParams, modsEnabled, modpacksSupported]);

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

    if (checkingSupport) {
        return (
            <PageContentBlock title={'Modpacks Browser'} header description={'Browse and install Minecraft modpacks.'}>
                <div css={tw`flex justify-center py-16`}>
                    <Spinner size={'large'} />
                </div>
            </PageContentBlock>
        );
    }

    if (!modpacksSupported) {
        return (
            <PageContentBlock title={'Modpacks Browser'} header description={'Browse and install Minecraft modpacks.'}>
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>Your server does not have modpack support.</p>
                    <p css={tw`text-neutral-400 text-sm mb-2`}>
                        This server is not configured with the required environment variables for modpack installation.
                    </p>
                    <p css={tw`text-neutral-400 text-xs`}>
                        Please contact an administrator to change your server to use a modpack-compatible egg (e.g., CurseForge Generic).
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
                />
            )}
        </PageContentBlock>
    );
};
