import { useEffect, useMemo, useRef, useState } from 'react';
import { useStoreState } from '@/state/hooks';
import {
    BatchInstallItem,
    ExtensionData,
    batchInstallExtensions,
    batchUninstallExtensions,
    batchUpdateExtensions,
    getExtensions,
    getInstallProgress,
    refreshExtensions,
} from '@/api/routes/admin/extensions';
import getVersion from '@/api/routes/admin/getVersion';
import CatalogCard from './CatalogCard';
import Spinner from '@/elements/Spinner';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import useFlash from '@/plugins/useFlash';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faArrowsRotate, faCheck, faDownload, faTrash, faXmark } from '@fortawesome/free-solid-svg-icons';

type PackageActionState = {
    extensionId: string;
    extensionName: string;
    type: 'install' | 'uninstall' | 'update';
};

type BatchProgress = {
    stage: string;
    total: number;
    current: number;
    extensionId: string;
    action: string;
};

export default () => {
    const { colors } = useStoreState(state => state.theme.data!);
    const [extensions, setExtensions] = useState<ExtensionData[]>([]);
    const [currentPanelVersion, setCurrentPanelVersion] = useState<string | undefined>(undefined);
    const [catalogFilter, setCatalogFilter] = useState('all');
    const [panelSupportFilter, setPanelSupportFilter] = useState('all');
    const [activePackageAction, setActivePackageAction] = useState<PackageActionState | null>(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [batchLoading, setBatchLoading] = useState(false);
    const [batchProgress, setBatchProgress] = useState<BatchProgress | null>(null);
    const batchPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    const withAlpha = (color: string, alpha: string) => `${color}${alpha}`;

    const getCatalogValue = (extension: ExtensionData): string => {
        if (extension.source?.type === 'core') {
            return 'catalog:core';
        }

        if (extension.source?.repositoryId) {
            return `catalog:${extension.source.repositoryId}`;
        }

        return `catalog:${extension.source?.label ?? 'unknown'}`;
    };

    const getCompatibleVersions = (extension: ExtensionData): string[] =>
        (extension.compatiblePanelVersions ?? []).filter((version): version is string => version.trim().length > 0);

    const fetchExtensions = () => {
        setLoading(true);
        getExtensions()
            .then(data => setExtensions(data))
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => setLoading(false));
    };

    const handleCheckForUpdates = () => {
        setRefreshing(true);
        clearFlashes('admin:extensions');
        refreshExtensions()
            .then(data => {
                setExtensions(data);
                addFlash({
                    key: 'admin:extensions',
                    type: 'success',
                    message: 'Repository manifests refreshed. Update status is now up to date.',
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => setRefreshing(false));
    };

    useEffect(() => {
        fetchExtensions();
        getVersion()
            .then(data => setCurrentPanelVersion(data.panel.current))
            .catch(() => setCurrentPanelVersion(undefined));
    }, []);

    const catalogOptions = useMemo(() => {
        const counts = new Map<string, { label: string; count: number; sort: number }>();

        extensions.forEach(extension => {
            const value = getCatalogValue(extension);
            const label = extension.source?.type === 'core'
                ? 'Core'
                : extension.source?.label ?? 'Unknown';
            const existing = counts.get(value);

            counts.set(value, {
                label,
                count: (existing?.count ?? 0) + 1,
                sort: value === 'catalog:core' ? 0 : 1,
            });
        });

        return Array.from(counts.entries())
            .map(([value, option]) => ({
                value,
                label: `${option.label} (${option.count})`,
                sort: option.sort,
            }))
            .sort((left, right) => {
                if (left.sort !== right.sort) {
                    return left.sort - right.sort;
                }

                return left.label.localeCompare(right.label);
            });
    }, [extensions]);

    const panelSupportOptions = useMemo(() => {
        const versionCounts = new Map<string, number>();
        let unrestrictedCount = 0;
        let currentCompatibleCount = 0;
        let currentUnsupportedCount = 0;

        extensions.forEach(extension => {
            const compatibleVersions = getCompatibleVersions(extension);

            if (compatibleVersions.length === 0) {
                unrestrictedCount += 1;
            } else {
                compatibleVersions.forEach(version => {
                    versionCounts.set(version, (versionCounts.get(version) ?? 0) + 1);
                });
            }

            if (currentPanelVersion) {
                const supportsCurrentPanel =
                    compatibleVersions.length === 0 || compatibleVersions.includes(currentPanelVersion);

                if (supportsCurrentPanel) {
                    currentCompatibleCount += 1;
                } else {
                    currentUnsupportedCount += 1;
                }
            }
        });

        return [
            { value: 'all', label: `All support states (${extensions.length})` },
            ...(currentPanelVersion
                ? [
                      {
                          value: 'current',
                          label: `Supports current panel (${currentPanelVersion}) (${currentCompatibleCount})`,
                      },
                      {
                          value: 'unsupported-current',
                          label: `Unsupported on current panel (${currentUnsupportedCount})`,
                      },
                  ]
                : []),
            ...(unrestrictedCount > 0
                ? [{ value: 'unrestricted', label: `No version restrictions (${unrestrictedCount})` }]
                : []),
            ...Array.from(versionCounts.entries())
                .sort(([left], [right]) => left.localeCompare(right))
                .map(([version, count]) => ({
                    value: `version:${version}`,
                    label: `Supports ${version} (${count + unrestrictedCount})`,
                })),
        ];
    }, [currentPanelVersion, extensions]);

    const filteredExtensions = useMemo(
        () =>
            extensions.filter(extension => {
                const catalogMatches = catalogFilter === 'all' || getCatalogValue(extension) === catalogFilter;
                if (!catalogMatches) {
                    return false;
                }

                const compatibleVersions = getCompatibleVersions(extension);
                switch (panelSupportFilter) {
                    case 'all':
                        return true;
                    case 'current':
                        return currentPanelVersion
                            ? compatibleVersions.length === 0 || compatibleVersions.includes(currentPanelVersion)
                            : true;
                    case 'unsupported-current':
                        return currentPanelVersion
                            ? compatibleVersions.length > 0 && !compatibleVersions.includes(currentPanelVersion)
                            : false;
                    case 'unrestricted':
                        return compatibleVersions.length === 0;
                    default:
                        if (panelSupportFilter.startsWith('version:')) {
                            const version = panelSupportFilter.slice('version:'.length);

                            return compatibleVersions.length === 0 || compatibleVersions.includes(version);
                        }

                        return true;
                }
            }),
        [catalogFilter, currentPanelVersion, extensions, panelSupportFilter]
    );

    const hasActiveFilters = catalogFilter !== 'all' || panelSupportFilter !== 'all';
    const activePackageActionMessage = activePackageAction
        ? `Wait for ${activePackageAction.extensionName} to finish ${activePackageAction.type === 'install' ? 'installing' : activePackageAction.type === 'uninstall' ? 'uninstalling' : 'updating'} before starting another extension install, update, or uninstall.`
        : null;

    const handlePackageActionStart = (action: PackageActionState) => {
        setActivePackageAction(action);
    };

    const handlePackageActionEnd = (extensionId: string) => {
        setActivePackageAction(current => (current?.extensionId === extensionId ? null : current));
    };

    const toggleSelection = (extensionId: string) => {
        setSelectedIds(current => {
            const next = new Set(current);
            if (next.has(extensionId)) {
                next.delete(extensionId);
            } else {
                next.add(extensionId);
            }
            return next;
        });
    };

    const clearSelection = () => setSelectedIds(new Set());

    const startBatchPoll = () => {
        if (batchPollRef.current !== null) return;
        batchPollRef.current = setInterval(() => {
            getInstallProgress()
                .then(progress => {
                    if (progress && (
                        progress.action === 'batch-install' ||
                        progress.action === 'batch-uninstall' ||
                        progress.action === 'batch-update'
                    )) {
                        setBatchProgress({
                            stage: progress.stage,
                            total: progress.batch_total ?? 1,
                            current: progress.batch_current ?? 1,
                            extensionId: progress.extension_id,
                            action: progress.action,
                        });
                    }
                })
                .catch(() => {});
        }, 1500);
    };

    const stopBatchPoll = () => {
        if (batchPollRef.current !== null) {
            clearInterval(batchPollRef.current);
            batchPollRef.current = null;
        }
    };

    const selectedExtensions = extensions.filter(ext => selectedIds.has(ext.id));
    const selectableForInstall = selectedExtensions.filter(
        ext => ext.installable && ext.source?.repositoryId
    );
    const selectableForUninstall = selectedExtensions.filter(ext => ext.canUninstall);
    const selectableForUpdate = selectedExtensions.filter(
        ext => ext.updateAvailable && ext.source?.repositoryId && ext.canUninstall
    );

    const handleBatchInstall = () => {
        if (selectableForInstall.length === 0) return;
        const hasThirdParty = selectableForInstall.some(ext => !ext.source?.official);
        const message = hasThirdParty
            ? `Install ${selectableForInstall.length} extension(s)? Some are from third-party repositories that can execute arbitrary PHP and frontend code inside M12Labs.`
            : `Install ${selectableForInstall.length} extension(s)?`;

        if (!window.confirm(message)) return;

        setBatchLoading(true);
        clearFlashes('admin:extensions');
        startBatchPoll();

        const items: BatchInstallItem[] = selectableForInstall.map(ext => ({
            extensionId: ext.id,
            repositoryId: ext.source!.repositoryId!,
        }));

        batchInstallExtensions(items)
            .then(updatedExtensions => {
                setExtensions(updatedExtensions);
                clearSelection();
                addFlash({
                    key: 'admin:extensions',
                    type: 'success',
                    message: `${selectableForInstall.length} extension(s) installed and M12Labs was rebuilt.`,
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => {
                setBatchLoading(false);
                setBatchProgress(null);
                stopBatchPoll();
            });
    };

    const handleBatchUninstall = () => {
        if (selectableForUninstall.length === 0) return;
        if (
            !window.confirm(
                `Uninstall ${selectableForUninstall.length} extension(s)? This removes their files and rebuilds M12Labs.`
            )
        ) return;

        setBatchLoading(true);
        clearFlashes('admin:extensions');
        startBatchPoll();

        batchUninstallExtensions(selectableForUninstall.map(ext => ext.id))
            .then(updatedExtensions => {
                setExtensions(updatedExtensions);
                clearSelection();
                addFlash({
                    key: 'admin:extensions',
                    type: 'success',
                    message: `${selectableForUninstall.length} extension(s) uninstalled and M12Labs was rebuilt.`,
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => {
                setBatchLoading(false);
                setBatchProgress(null);
                stopBatchPoll();
            });
    };

    const handleBatchUpdate = () => {
        if (selectableForUpdate.length === 0) return;
        if (
            !window.confirm(
                `Update ${selectableForUpdate.length} extension(s) to their latest versions? M12Labs will be rebuilt once.`
            )
        ) return;

        setBatchLoading(true);
        clearFlashes('admin:extensions');
        startBatchPoll();

        const items: BatchInstallItem[] = selectableForUpdate.map(ext => ({
            extensionId: ext.id,
            repositoryId: ext.source!.repositoryId!,
        }));

        batchUpdateExtensions(items)
            .then(updatedExtensions => {
                setExtensions(updatedExtensions);
                clearSelection();
                addFlash({
                    key: 'admin:extensions',
                    type: 'success',
                    message: `${selectableForUpdate.length} extension(s) updated and M12Labs was rebuilt.`,
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:extensions', error }))
            .finally(() => {
                setBatchLoading(false);
                setBatchProgress(null);
                stopBatchPoll();
            });
    };

    if (loading) {
        return (
            <div className={'flex items-center justify-center py-16'}>
                <Spinner size={'large'} />
            </div>
        );
    }

    if (extensions.length === 0) {
        return (
            <div
                className={'rounded-lg border p-8 text-center'}
                style={{ backgroundColor: colors.secondary, borderColor: colors.headers }}
            >
                <p className={'text-neutral-300'}>No extensions are available.</p>
                <p className={'mt-2 text-sm text-neutral-500'}>
                    Add a repository on the Repositories tab to publish installable packages into this catalog.
                </p>
            </div>
        );
    }

    return (
        <div className={'space-y-6'}>
            <div
                className={'rounded-lg border p-4'}
                style={{ backgroundColor: withAlpha(colors.primary, '10'), borderColor: colors.primary }}
            >
                <p className={'text-sm font-semibold'} style={{ color: colors.primary }}>
                    Repository Safety
                </p>
                <p className={'mt-2 text-sm text-neutral-300'}>
                    Repository installs can add PHP, routes, and frontend code to M12Labs. Checksums verify integrity
                    against the selected repository manifest, but they do not make third-party code safe by themselves.
                </p>
            </div>

            {activePackageActionMessage && (
                <div
                    className={'rounded-lg border p-4'}
                    style={{ backgroundColor: withAlpha(colors.primary, '10'), borderColor: colors.primary }}
                >
                    <p className={'text-sm font-semibold'} style={{ color: colors.primary }}>
                        Extension action in progress
                    </p>
                    <p className={'mt-2 text-sm text-neutral-300'}>{activePackageActionMessage}</p>
                </div>
            )}

            {batchProgress && (
                <div
                    className={'rounded-lg border p-4'}
                    style={{ backgroundColor: withAlpha(colors.primary, '10'), borderColor: colors.primary }}
                >
                    <p className={'text-sm font-semibold'} style={{ color: colors.primary }}>
                        Batch operation in progress
                    </p>
                    <p className={'mt-1 text-sm text-neutral-300'}>
                        {batchProgress.action === 'batch-install' ? 'Installing' :
                         batchProgress.action === 'batch-uninstall' ? 'Uninstalling' : 'Updating'}{' '}
                        extension {batchProgress.current} of {batchProgress.total}
                        {batchProgress.extensionId ? ` (${batchProgress.extensionId})` : ''}
                        {' '}— {batchProgress.stage}…
                    </p>
                    <div className={'mt-2 h-1.5 rounded-full bg-neutral-700'}>
                        <div
                            className={'h-1.5 rounded-full transition-all duration-500'}
                            style={{
                                backgroundColor: colors.primary,
                                width: `${Math.round((batchProgress.current / batchProgress.total) * 100)}%`,
                            }}
                        />
                    </div>
                </div>
            )}

            {selectedIds.size > 0 && (
                <div
                    className={'sticky top-4 z-10 rounded-xl border p-4 shadow-lg'}
                    style={{ backgroundColor: colors.secondary, borderColor: colors.primary }}
                >
                    <div className={'flex flex-wrap items-center gap-3'}>
                        <span className={'flex-1 text-sm font-semibold text-neutral-100'}>
                            {selectedIds.size} extension{selectedIds.size === 1 ? '' : 's'} selected
                        </span>

                        {selectableForInstall.length > 0 && (
                            <Button
                                onClick={handleBatchInstall}
                                loading={batchLoading}
                                disabled={batchLoading || !!activePackageAction}
                                icon={() => <FontAwesomeIcon icon={faDownload} />}
                            >
                                Install {selectableForInstall.length}
                            </Button>
                        )}

                        {selectableForUpdate.length > 0 && (
                            <Button
                                onClick={handleBatchUpdate}
                                loading={batchLoading}
                                disabled={batchLoading || !!activePackageAction}
                                icon={() => <FontAwesomeIcon icon={faArrowsRotate} />}
                            >
                                Update {selectableForUpdate.length}
                            </Button>
                        )}

                        {selectableForUninstall.length > 0 && (
                            <Button.Danger
                                onClick={handleBatchUninstall}
                                loading={batchLoading}
                                disabled={batchLoading || !!activePackageAction}
                                icon={() => <FontAwesomeIcon icon={faTrash} />}
                            >
                                Uninstall {selectableForUninstall.length}
                            </Button.Danger>
                        )}

                        <Button.Text
                            onClick={clearSelection}
                            disabled={batchLoading}
                            variant={Button.Variants.Secondary}
                        >
                            <FontAwesomeIcon icon={faXmark} className={'mr-1'} />
                            Clear
                        </Button.Text>
                    </div>
                </div>
            )}

            <div
                className={'rounded-xl border p-5'}
                style={{ backgroundColor: colors.secondary, borderColor: colors.headers }}
            >
                <div className={'flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between'}>
                    <div>
                        <h3 className={'text-base font-semibold text-neutral-100'}>Catalog filters</h3>
                        <p className={'mt-1 text-sm text-neutral-400'}>
                            Showing {filteredExtensions.length} of {extensions.length} package
                            {extensions.length === 1 ? '' : 's'}
                            {currentPanelVersion ? ` for panel ${currentPanelVersion}` : ''}.
                        </p>
                    </div>
                    <div className={'flex items-center gap-2'}>
                        <Button.Text
                            onClick={handleCheckForUpdates}
                            loading={refreshing}
                            disabled={refreshing || !!activePackageAction}
                            variant={Button.Variants.Secondary}
                        >
                            <FontAwesomeIcon icon={faArrowsRotate} className={'mr-2'} />
                            Check for updates
                        </Button.Text>
                        <Button.Text
                            onClick={() => {
                                const installable = filteredExtensions.filter(
                                    ext => ext.installable && ext.source?.repositoryId
                                );
                                const ids = new Set(installable.map(ext => ext.id));
                                setSelectedIds(ids);
                            }}
                            disabled={!!activePackageAction || batchLoading}
                            variant={Button.Variants.Secondary}
                        >
                            <FontAwesomeIcon icon={faCheck} className={'mr-2'} />
                            Select installable
                        </Button.Text>
                        <Button.Text
                            onClick={() => {
                                const updatable = filteredExtensions.filter(
                                    ext => ext.updateAvailable && ext.source?.repositoryId && ext.canUninstall
                                );
                                const ids = new Set(updatable.map(ext => ext.id));
                                setSelectedIds(ids);
                            }}
                            disabled={!!activePackageAction || batchLoading}
                            variant={Button.Variants.Secondary}
                        >
                            <FontAwesomeIcon icon={faArrowsRotate} className={'mr-2'} />
                            Select updatable
                        </Button.Text>
                        <Button.Text
                            onClick={() => {
                                setCatalogFilter('all');
                                setPanelSupportFilter('all');
                            }}
                            disabled={!hasActiveFilters}
                            variant={Button.Variants.Secondary}
                        >
                            Clear filters
                        </Button.Text>
                    </div>
                </div>

                <div className={'mt-4 grid gap-4 lg:grid-cols-2'}>
                    <div className={'space-y-2'}>
                        <label className={'block text-xs font-semibold uppercase tracking-wide text-neutral-400'}>
                            Catalog
                        </label>
                        <Select
                            value={catalogFilter}
                            onChange={event => setCatalogFilter(event.currentTarget.value)}
                            style={{ backgroundColor: colors.background, borderColor: colors.headers }}
                        >
                            <option value={'all'}>All catalogs ({extensions.length})</option>
                            {catalogOptions.map(option => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div className={'space-y-2'}>
                        <label className={'block text-xs font-semibold uppercase tracking-wide text-neutral-400'}>
                            Panel support
                        </label>
                        <Select
                            value={panelSupportFilter}
                            onChange={event => setPanelSupportFilter(event.currentTarget.value)}
                            style={{ backgroundColor: colors.background, borderColor: colors.headers }}
                        >
                            {panelSupportOptions.map(option => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
            </div>

            {filteredExtensions.length === 0 ? (
                <div
                    className={'rounded-xl border p-8 text-center'}
                    style={{ backgroundColor: colors.secondary, borderColor: colors.headers }}
                >
                    <p className={'text-neutral-200'}>No extensions match the selected filters.</p>
                    <p className={'mt-2 text-sm text-neutral-500'}>
                        Try a different catalog or panel support filter.
                    </p>
                </div>
            ) : (
                <div className={'grid gap-6 sm:grid-cols-2 xl:grid-cols-3'}>
                    {filteredExtensions.map((extension: ExtensionData) => (
                        <CatalogCard
                            key={extension.id}
                            extension={extension}
                            currentPanelVersion={currentPanelVersion}
                            activePackageAction={activePackageAction}
                            isSelected={selectedIds.has(extension.id)}
                            onToggleSelect={() => toggleSelection(extension.id)}
                            onRefresh={fetchExtensions}
                            onPackageActionStart={handlePackageActionStart}
                            onPackageActionEnd={handlePackageActionEnd}
                        />
                    ))}
                </div>
            )}
        </div>
    );
};
