import { useEffect, useMemo, useState } from 'react';
import { useStoreState } from '@/state/hooks';
import { ExtensionData, getExtensions } from '@/api/routes/admin/extensions';
import getVersion from '@/api/routes/admin/getVersion';
import CatalogCard from './CatalogCard';
import Spinner from '@/elements/Spinner';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import useFlash from '@/plugins/useFlash';

type PackageActionState = {
    extensionId: string;
    extensionName: string;
    type: 'install' | 'uninstall';
};

export default () => {
    const { colors } = useStoreState(state => state.theme.data!);
    const [extensions, setExtensions] = useState<ExtensionData[]>([]);
    const [currentPanelVersion, setCurrentPanelVersion] = useState<string | undefined>(undefined);
    const [catalogFilter, setCatalogFilter] = useState('all');
    const [panelSupportFilter, setPanelSupportFilter] = useState('all');
    const [activePackageAction, setActivePackageAction] = useState<PackageActionState | null>(null);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError } = useFlash();

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
        ? `Wait for ${activePackageAction.extensionName} to finish ${activePackageAction.type === 'install' ? 'installing' : 'uninstalling'} before starting another extension install or uninstall.`
        : null;

    const handlePackageActionStart = (action: PackageActionState) => {
        setActivePackageAction(action);
    };

    const handlePackageActionEnd = (extensionId: string) => {
        setActivePackageAction(current => (current?.extensionId === extensionId ? null : current));
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
