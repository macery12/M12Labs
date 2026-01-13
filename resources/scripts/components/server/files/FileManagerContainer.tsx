import type { ChangeEvent } from 'react';
import { useEffect } from 'react';
import tw from 'twin.macro';

import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';
import FileObjectGrid from '@server/files/FileObjectGrid';
import FileManagerBreadcrumbs from '@server/files/FileManagerBreadcrumbs';
import { type FileObject } from '@definitions/server';
import NewDirectoryButton from '@server/files/NewDirectoryButton';
import { NavLink, useLocation } from 'react-router-dom';
import Can from '@/elements/Can';
import { ServerError } from '@/elements/ScreenBlock';
import { Button } from '@/elements/button/index';
import { ServerContext } from '@/state/server';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import FileManagerStatus from '@server/files/FileManagerStatus';
import MassActionsBar from '@server/files/MassActionsBar';
import UploadButton from '@server/files/UploadButton';
import { useStoreActions, useStoreState } from '@/state/hooks';
import ErrorBoundary from '@/elements/ErrorBoundary';
import { FileActionCheckbox } from '@server/files/SelectFileCheckbox';
import style from './style.module.css';
import FadeTransition from '@/elements/transitions/FadeTransition';
import { usePersistedState } from '@/plugins/usePersistedState';
import { faBorderAll, faFolderPlus, faList } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import FileObjectList from './FileObjectList';
import CopyOnClick from '@/elements/CopyOnClick';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { ip } from '@/lib/formatters';
import PageContentBlock from '@/elements/PageContentBlock';
import { hashToPath } from '@/lib/helpers';
import FileSortControls from '@server/files/FileSortControls';
import type { SortField, SortDirection } from '@/state/server/files';

const sortFiles = (files: FileObject[], sortField: SortField, sortDirection: SortDirection): FileObject[] => {
    const sorted = [...files].sort((a, b) => {
        // Always put directories first, then files
        if (a.isFile !== b.isFile) {
            return a.isFile ? 1 : -1;
        }

        let comparison = 0;

        switch (sortField) {
            case 'name':
                comparison = a.name.localeCompare(b.name);
                break;
            case 'modified':
                comparison = a.modifiedAt.getTime() - b.modifiedAt.getTime();
                break;
            case 'size':
                comparison = a.size - b.size;
                break;
            case 'type': {
                // For type, we sort by file extension
                const extA = a.isFile ? a.name.split('.').pop()?.toLowerCase() || '' : '';
                const extB = b.isFile ? b.name.split('.').pop()?.toLowerCase() || '' : '';
                comparison = extA.localeCompare(extB);
                break;
            }
        }

        return sortDirection === 'asc' ? comparison : -comparison;
    });

    // Remove duplicates
    return sorted.filter((file, index) => index === 0 || file.name !== sorted[index - 1]?.name);
};

const filterFiles = (files: FileObject[], searchTerm: string): FileObject[] => {
    if (!searchTerm.trim()) {
        return files;
    }

    const lowerSearchTerm = searchTerm.toLowerCase();
    return files.filter(file => file.name.toLowerCase().includes(lowerSearchTerm));
};

export default () => {
    const id = ServerContext.useStoreState(state => state.server.data!.id);
    const { hash } = useLocation();
    const { data: files, error, mutate } = useFileManagerSwr();
    const directory = ServerContext.useStoreState(state => state.files.directory);
    const clearFlashes = useStoreActions(actions => actions.flashes.clearFlashes);
    const setDirectory = ServerContext.useStoreActions(actions => actions.files.setDirectory);
    const [gridView, setGridView] = usePersistedState<boolean>(`${id}_file_manager_view`, false);
    const sortField = ServerContext.useStoreState(state => state.files.sortField);
    const sortDirection = ServerContext.useStoreState(state => state.files.sortDirection);
    const searchTerm = ServerContext.useStoreState(state => state.files.searchTerm);
    const setSortField = ServerContext.useStoreActions(actions => actions.files.setSortField);
    const setSortDirection = ServerContext.useStoreActions(actions => actions.files.setSortDirection);
    const [persistedSortField, setPersistedSortField] = usePersistedState<SortField>(
        `${id}_file_manager_sort_field`,
        'name',
    );
    const [persistedSortDirection, setPersistedSortDirection] = usePersistedState<SortDirection>(
        `${id}_file_manager_sort_direction`,
        'asc',
    );

    const sftp = ServerContext.useStoreState(state => state.server.data!.sftpDetails);
    const username = useStoreState(state => state.user.data!.username);
    const setSelectedFiles = ServerContext.useStoreActions(actions => actions.files.setSelectedFiles);
    const selectedFilesLength = ServerContext.useStoreState(state => state.files.selectedFiles.length);

    // Initialize sort settings from persisted state on mount
    useEffect(() => {
        setSortField(persistedSortField);
        setSortDirection(persistedSortDirection);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Persist sort settings when they change
    useEffect(() => {
        setPersistedSortField(sortField);
        setPersistedSortDirection(sortDirection);
    }, [sortField, sortDirection, setPersistedSortField, setPersistedSortDirection]);

    useEffect(() => {
        clearFlashes('files');
        setSelectedFiles([]);
        setDirectory(hashToPath(hash));
    }, [hash]);

    useEffect(() => {
        void mutate();
    }, [directory]);

    const onSelectAllClick = (e: ChangeEvent<HTMLInputElement>) => {
        setSelectedFiles(e.currentTarget.checked ? files?.map(file => file.name) || [] : []);
    };

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => mutate()} />;
    }

    return (
        <PageContentBlock
            title={'File Manager'}
            header
            description={'Control your files and folders via the UI.'}
            showFlashKey={'files'}
        >
            <ErrorBoundary>
                <div className={'mb-4 flex flex-wrap-reverse md:flex-nowrap'}>
                    <FileManagerBreadcrumbs
                        renderLeft={
                            <FileActionCheckbox
                                type={'checkbox'}
                                css={tw`mx-4`}
                                checked={selectedFilesLength === (files?.length === 0 ? -1 : files?.length)}
                                onChange={onSelectAllClick}
                            />
                        }
                    />
                    <Can action={'file.create'}>
                        <div className={style.manager_actions}>
                            <FileManagerStatus />
                            <NewDirectoryButton />
                            <UploadButton />
                            <NavLink to={`/server/${id}/files/new${window.location.hash}`}>
                                <Button>New File</Button>
                            </NavLink>
                            <Button onClick={() => setGridView(!gridView)}>
                                <FontAwesomeIcon icon={gridView ? faList : faBorderAll} fixedWidth />
                            </Button>
                        </div>
                    </Can>
                </div>
                <div className={'mb-4'}>
                    <FileSortControls />
                </div>
            </ErrorBoundary>
            <div className={'grid gap-4 xl:grid-cols-4'}>
                <div className={'xl:col-span-3'}>
                    {!files ? (
                        <Spinner size={'large'} centered />
                    ) : (
                        <>
                            {(() => {
                                // Filter files first based on search term
                                const filteredFiles = filterFiles(files, searchTerm);
                                // Then sort the filtered results
                                const sortedFiles = sortFiles(filteredFiles, sortField, sortDirection);
                                // Finally, limit to 250 files for display
                                const displayFiles = sortedFiles.slice(0, 250);

                                if (!filteredFiles.length) {
                                    return (
                                        <p css={tw`text-sm text-neutral-400 text-center`}>
                                            {searchTerm
                                                ? 'No files found matching your search.'
                                                : 'This directory seems to be empty.'}
                                        </p>
                                    );
                                }

                                return (
                                    <FadeTransition duration="duration-150" appear show>
                                        <div>
                                            {filteredFiles.length > 250 && (
                                                <div css={tw`rounded bg-yellow-400 mb-px p-3`}>
                                                    <p css={tw`text-yellow-900 text-sm text-center`}>
                                                        {searchTerm
                                                            ? `Found ${filteredFiles.length} files matching your search, showing first 250.`
                                                            : 'This directory is too large to display in the browser, limiting the output to the first 250 files.'}
                                                    </p>
                                                </div>
                                            )}
                                            {searchTerm && filteredFiles.length <= 250 && (
                                                <div css={tw`rounded bg-blue-500 mb-px p-3`}>
                                                    <p css={tw`text-blue-50 text-sm text-center`}>
                                                        Found {filteredFiles.length} file
                                                        {filteredFiles.length !== 1 ? 's' : ''} matching &quot;
                                                        {searchTerm}&quot;
                                                    </p>
                                                </div>
                                            )}
                                            {gridView ? (
                                                <div className={'grid grid-cols-2 gap-2 lg:grid-cols-6 lg:gap-4'}>
                                                    {displayFiles.map(file => (
                                                        <FileObjectGrid key={file.key} file={file} />
                                                    ))}
                                                </div>
                                            ) : (
                                                <>
                                                    {displayFiles.map(file => (
                                                        <FileObjectList key={file.key} file={file} />
                                                    ))}
                                                </>
                                            )}
                                            <MassActionsBar />
                                        </div>
                                    </FadeTransition>
                                );
                            })()}
                        </>
                    )}
                </div>
                <Can action={'file.sftp'}>
                    <TitledGreyBox title={'SFTP Details'} icon={faFolderPlus} css={tw`xl:mt-0 mt-6 h-auto`}>
                        <div>
                            <Label>Server Address</Label>
                            <CopyOnClick text={`sftp://${ip(sftp.ip)}:${sftp.port}`}>
                                <Input type={'text'} value={`sftp://${ip(sftp.ip)}:${sftp.port}`} readOnly />
                            </CopyOnClick>
                        </div>
                        <div css={tw`mt-6`}>
                            <Label>Username</Label>
                            <CopyOnClick text={`${username}.${id}`}>
                                <Input type={'text'} value={`${username}.${id}`} readOnly />
                            </CopyOnClick>
                        </div>
                        <div css={tw`mt-6 flex items-center`}>
                            <div css={tw`flex-1`}>
                                <div css={tw`border-l-4 border-cyan-500 p-3`}>
                                    <p css={tw`text-xs text-neutral-200`}>
                                        Your SFTP password is the same as the password you use to access this panel.
                                    </p>
                                </div>
                            </div>
                            <div css={tw`ml-4`}>
                                <a href={`sftp://${username}.${id}@${ip(sftp.ip)}:${sftp.port}`}>
                                    <Button.Text variant={Button.Variants.Secondary}>Launch SFTP</Button.Text>
                                </a>
                            </div>
                        </div>
                    </TitledGreyBox>
                </Can>
            </div>
        </PageContentBlock>
    );
};
