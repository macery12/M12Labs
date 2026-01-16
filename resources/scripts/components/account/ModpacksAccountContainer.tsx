import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import ContentBox from '@/elements/ContentBox';
import ScopedAlert from '@/components/account/ScopedAlert';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { type CurseForgeModpack, type ModpackSearchParams } from '@/api/routes/server/modpacks';
import { searchModpacks } from '@/api/routes/account/modpacks';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';
import ModpackSearch from './modpacks/ModpackSearch';
import ModpackList from './modpacks/ModpackList';
import ModpackInstallModal from './modpacks/ModpackInstallModal';

export default () => {
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const { addError } = useFlash();

    const [loading, setLoading] = useState(false);
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

    useEffect(() => {
        if (!globalModsEnabled) return;

        setLoading(true);
        searchModpacks(searchParams)
            .then(response => {
                setModpacks(response.data);
                setPagination(response.pagination);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'account:modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [searchParams, globalModsEnabled]);

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
            <PageContentBlock title={'Modpacks'} header description={'Browse and install Minecraft modpacks to your servers.'}>
                <ScopedAlert scope="account" position="top-center" />
                <ContentBox>
                    <div css={tw`text-center py-16`}>
                        <p css={tw`text-neutral-300 text-lg mb-4`}>The Mods module is not enabled.</p>
                        <p css={tw`text-neutral-400 text-sm`}>
                            Contact your panel administrator to enable the Mods module in the admin area.
                        </p>
                    </div>
                </ContentBox>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock
            title={'Modpacks'}
            header
            description={'Browse and install Minecraft modpacks from CurseForge to your servers.'}
        >
            <ScopedAlert scope="account" position="top-center" />
            <FlashMessageRender byKey={'account:modpacks'} />

            <ContentBox css={tw`mb-4`}>
                <ModpackSearch onSearch={handleSearch} initialParams={searchParams} />
            </ContentBox>

            {loading && !modpacks.length ? (
                <ContentBox>
                    <div css={tw`py-8`}>
                        <Spinner size={'large'} centered />
                    </div>
                </ContentBox>
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
                <ModpackInstallModal
                    key={selectedModpack.id}
                    modpack={selectedModpack}
                    onClose={() => setSelectedModpack(null)}
                />
            )}
        </PageContentBlock>
    );
};
