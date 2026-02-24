import { type JSX, useEffect, useMemo, useState } from 'react';
import type { Layout, ResponsiveProps } from 'react-grid-layout';
import { Responsive, WidthProvider } from 'react-grid-layout';
import classNames from 'classnames';
import { DotsVerticalIcon, EyeIcon, EyeOffIcon, PencilAltIcon, PlusIcon, RefreshIcon, SaveIcon } from '@heroicons/react/outline';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import { Dialog } from '@/elements/dialog';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import { ServerContext } from '@/state/server';
import type { ConsoleWorkspaceLayout, ConsoleWorkspaceModuleId } from '@definitions/server';
import { resetConsoleWorkspaceLayout, saveConsoleWorkspaceLayout, useConsoleWorkspaceLayout } from '@/api/routes/server';
import Console from '@server/console/Console';
import {
    AddressModule,
    CpuGraphModule,
    CpuSummaryModule,
    DiskSummaryModule,
    MemoryGraphModule,
    MemorySummaryModule,
    NetworkGraphModule,
    UptimeModule,
} from '@server/console/workspace/modules';
import { ConsoleStatsProvider } from '@server/console/ConsoleStatsProvider';
import styles from './workspace/styles.module.css';
import type { WorkspaceModuleDefinition, WorkspaceLayoutState } from './workspace/types';

import 'react-grid-layout/css/styles.css';
import 'react-resizable/css/styles.css';

const ResponsiveGridLayout = WidthProvider(Responsive);

const GRID_COLS: ResponsiveProps['cols'] = { lg: 12, md: 12, sm: 6, xs: 4, xxs: 2 };
const ROW_HEIGHT = 36;
const FLASH_KEY = 'console:share';

const defaultLayout: ConsoleWorkspaceLayout = {
    version: 1,
    hidden: [],
    layout: [
        { id: 'address', i: 'address', x: 0, y: 0, w: 3, h: 3, minW: 2, minH: 1 },
        { id: 'uptime', i: 'uptime', x: 3, y: 0, w: 3, h: 3, minW: 2, minH: 1 },
        { id: 'cpuSummary', i: 'cpuSummary', x: 6, y: 0, w: 2, h: 3, minW: 2, minH: 1 },
        { id: 'memorySummary', i: 'memorySummary', x: 8, y: 0, w: 2, h: 3, minW: 2, minH: 1 },
        { id: 'diskSummary', i: 'diskSummary', x: 10, y: 0, w: 2, h: 3, minW: 2, minH: 1 },
        { id: 'console', i: 'console', x: 0, y: 3, w: 9, h: 18, minW: 6, minH: 6 },
        { id: 'cpuGraph', i: 'cpuGraph', x: 9, y: 3, w: 3, h: 6, minW: 3, minH: 2 },
        { id: 'memoryGraph', i: 'memoryGraph', x: 9, y: 9, w: 3, h: 6, minW: 3, minH: 2 },
        { id: 'networkGraph', i: 'networkGraph', x: 9, y: 15, w: 3, h: 6, minW: 3, minH: 2 },
    ],
};

const modules: WorkspaceModuleDefinition[] = [
    {
        id: 'address',
        title: 'Address',
        description: 'Primary IP and port for this server.',
        minW: 2,
        minH: 1,
        component: <AddressModule />,
    },
    {
        id: 'uptime',
        title: 'Uptime',
        description: 'Current uptime for the running server.',
        minW: 2,
        minH: 1,
        component: <UptimeModule />,
    },
    {
        id: 'cpuSummary',
        title: 'CPU Load',
        description: 'Live CPU usage summary.',
        minW: 2,
        minH: 1,
        component: <CpuSummaryModule />,
    },
    {
        id: 'memorySummary',
        title: 'Memory',
        description: 'Live memory usage summary.',
        minW: 2,
        minH: 1,
        component: <MemorySummaryModule />,
    },
    {
        id: 'diskSummary',
        title: 'Disk',
        description: 'Disk usage summary.',
        minW: 2,
        minH: 1,
        component: <DiskSummaryModule />,
    },
    {
        id: 'console',
        title: 'Console',
        description: 'Interactive server console.',
        minW: 6,
        minH: 3,
        component: null,
    },
    {
        id: 'cpuGraph',
        title: 'CPU Graph',
        description: 'Historical CPU usage graph.',
        minW: 3,
        minH: 2,
        component: <CpuGraphModule />,
    },
    {
        id: 'memoryGraph',
        title: 'Memory Graph',
        description: 'Historical memory usage graph.',
        minW: 3,
        minH: 2,
        component: <MemoryGraphModule />,
    },
    {
        id: 'networkGraph',
        title: 'Network Graph',
        description: 'Historical network usage graph.',
        minW: 3,
        minH: 2,
        component: <NetworkGraphModule />,
    },
];

const normalizeLayout = (layout?: ConsoleWorkspaceLayout): WorkspaceLayoutState => {
    const base = layout ?? defaultLayout;
    const normalizedLayout = (base.layout || []).map(item => {
        const id = (item as any).id ?? (item as any).i;
        return { ...item, id, i: id };
    });

    const ensured = modules.reduce<WorkspaceLayoutState>(
        (current, mod) => {
            const exists = current.layout.find(item => item.id === mod.id);
            return exists ? current : { ...current, layout: [...current.layout, defaultLayout.layout.find(l => l.id === mod.id)!] };
        },
        {
            version: base.version || defaultLayout.version,
            hidden: base.hidden ?? [],
            layout: normalizedLayout,
        },
    );

    return ensured;
};

const moduleMap = modules.reduce(
    (acc, module) => ({ ...acc, [module.id]: module }),
    {} as Record<ConsoleWorkspaceModuleId, WorkspaceModuleDefinition>,
);

interface Props {
    editMode: boolean;
    onToggleEdit: () => void;
}

const ConsoleWorkspace = ({ editMode, onToggleEdit }: Props) => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const theme = useStoreState(state => state.theme.data);
    const [expand, setExpand] = useState(false);

    const { data, isValidating, mutate } = useConsoleWorkspaceLayout();
    const [layout, setLayout] = useState<WorkspaceLayoutState>(normalizeLayout());
    const [initial, setInitial] = useState<WorkspaceLayoutState>(normalizeLayout());
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (data) {
            const normalized = normalizeLayout(data);
            setLayout(normalized);
            setInitial(normalized);
        }
    }, [data]);

    useEffect(() => {
        if (!editMode) {
            setDrawerOpen(false);
        }
    }, [editMode]);

    const dirty = useMemo(() => JSON.stringify(layout) !== JSON.stringify(initial), [layout, initial]);

    const applyNormalized = (grid: Layout[]) => {
        setLayout(current => ({
            ...current,
            hidden: current.hidden ?? [],
            layout: current.layout.map(item => {
                const next = grid.find(l => l.i === item.id);
                return next
                    ? {
                          ...item,
                          x: next.x,
                          y: next.y,
                          w: next.w,
                          h: next.h,
                      }
                    : item;
            }),
        }));
    };

    const hideModule = (id: ConsoleWorkspaceModuleId) =>
        setLayout(current => ({ ...current, hidden: Array.from(new Set([...current.hidden, id])) }));

    const addModule = (id: ConsoleWorkspaceModuleId) =>
        setLayout(current => ({
            ...ensureLayoutHasModule(current, id),
            hidden: current.hidden.filter(item => item !== id),
        }));

    const handleReset = async () => {
        clearFlashes(FLASH_KEY);
        setSaving(true);
        try {
            const next = await resetConsoleWorkspaceLayout(uuid);
            setLayout(normalizeLayout(next));
            setInitial(normalizeLayout(next));
            addFlash({ key: FLASH_KEY, type: 'success', message: 'Layout reset to default.' });
        } catch (error) {
            clearAndAddHttpError({ key: FLASH_KEY, error });
        } finally {
            setSaving(false);
        }
    };

    const handleSave = async () => {
        clearFlashes(FLASH_KEY);
        setSaving(true);
        try {
            const payload = normalizeLayout({ ...layout, hidden: layout.hidden ?? [] });
            const next = await saveConsoleWorkspaceLayout(uuid, payload);
            setLayout(payload);
            setInitial(normalizeLayout(next));
            addFlash({ key: FLASH_KEY, type: 'success', message: 'Layout saved.' });
            mutate(next, false);
        } catch (error) {
            clearAndAddHttpError({ key: FLASH_KEY, error });
        } finally {
            setSaving(false);
        }
    };

    const activeModules = layout.layout.filter(item => !layout.hidden.includes(item.id));
    const renderedModules = expand && !editMode ? activeModules.filter(item => item.id === 'console') : activeModules;
    const collapsed = useMemo(() => renderedModules.length > 0 && renderedModules.every(item => item.x === 0), [renderedModules]);
    const mappedLayout = useMemo(
        () =>
            renderedModules.map(item => ({
                i: item.id,
                x: item.x,
                y: item.y,
                w: item.w,
                h: item.h,
                minW: moduleMap[item.id].minW ?? item.minW,
                minH: moduleMap[item.id].minH ?? item.minH,
                static: false,
            })),
        [renderedModules],
    );

    const cloneLayout = () => mappedLayout.map(item => ({ ...item }));

    const layouts = useMemo(
        () => ({
            lg: cloneLayout(),
            md: cloneLayout(),
            sm: cloneLayout(),
            xs: cloneLayout(),
            xxs: cloneLayout(),
        }),
        [mappedLayout],
    );

    const gridStyles = useMemo(
        () => ({
            backgroundColor: theme?.colors?.black ?? '#0b1220',
        }),
        [theme],
    );

    const dragHandle = editMode ? `.${styles.workspaceHandle}` : undefined;

    useEffect(() => {
        if (!collapsed) return;
        setLayout(normalizeLayout());
    }, [collapsed]);

    return (
        <ConsoleStatsProvider>
            {editMode && (
                <div className={'mb-4 flex flex-wrap items-center justify-between gap-3'}>
                    <div className={'flex items-center gap-2'}>
                        <Button.Dark onClick={onToggleEdit} icon={PencilAltIcon}>
                            Exit edit
                        </Button.Dark>
                        <Button.Info onClick={() => setDrawerOpen(true)} icon={PlusIcon}>
                            Add module
                        </Button.Info>
                        <Button.Dark onClick={handleReset} disabled={saving} icon={RefreshIcon}>
                            Reset to default
                        </Button.Dark>
                        <Button.Success onClick={handleSave} disabled={!dirty || saving} icon={SaveIcon}>
                            Save layout
                        </Button.Success>
                    </div>
                    {dirty && <span className={'text-sm text-amber-300'}>Unsaved layout changes</span>}
                </div>
            )}

            {isValidating && !data ? (
                <div className={'flex justify-center py-10'}>
                    <Spinner />
                </div>
            ) : (
                <div className={styles.gridWrapper} style={gridStyles}>
                    <ResponsiveGridLayout
                        className={'layout'}
                        isDraggable={editMode}
                        isResizable={editMode}
                        draggableHandle={dragHandle}
                        breakpoints={{ lg: 1200, md: 996, sm: 768, xs: 480, xxs: 0 }}
                        cols={GRID_COLS}
                        rowHeight={ROW_HEIGHT}
                        margin={[12, 12]}
                        containerPadding={[0, 0]}
                        layouts={layouts}
                        onLayoutChange={applyNormalized}
                        onDragStop={applyNormalized}
                        onResizeStop={applyNormalized}
                        compactType={null}
                        isBounded
                    >
                        {renderedModules.map(item => {
                            const module = moduleMap[item.id];
                            const content =
                                item.id === 'console' ? (
                                    <div className={'flex h-full flex-col'}>
                                        <Console expand={expand} setExpand={setExpand} />
                                    </div>
                                ) : (
                                    module.component
                                );

                            return (
                                <div key={item.id} className={classNames(styles.module, editMode && styles.editable)}>
                                    {editMode && (
                                        <div className={styles.moduleHeader}>
                                            <div className={classNames(styles.workspaceHandle, 'flex cursor-move items-center gap-2')}>
                                                <DotsVerticalIcon className={'h-4 w-4 text-slate-200'} />
                                                <p className={'text-sm font-semibold text-slate-100'}>{module.title}</p>
                                            </div>
                                            <button
                                                type={'button'}
                                                className={styles.hideButton}
                                                onClick={() => hideModule(item.id)}
                                                aria-label={`Hide ${module.title}`}
                                            >
                                                <EyeOffIcon className={'h-4 w-4'} />
                                            </button>
                                        </div>
                                    )}
                                    <div className={styles.moduleBody}>
                                        <div className={'flex h-full flex-col min-h-0'}>{content}</div>
                                    </div>
                                </div>
                            );
                        })}
                    </ResponsiveGridLayout>
                </div>
            )}

            {editMode && (
                <Dialog open={drawerOpen} onClose={() => setDrawerOpen(false)} title={'Add modules'}>
                    <div className={'space-y-3'}>
                        {modules
                            .filter(mod => layout.hidden.includes(mod.id))
                            .map(mod => (
                                <div key={mod.id} className={'flex items-center justify-between rounded-md border border-slate-700/60 bg-slate-900/60 p-3'}>
                                    <div>
                                        <p className={'font-semibold'}>{mod.title}</p>
                                        <p className={'text-sm text-slate-300'}>{mod.description}</p>
                                    </div>
                                    <Button onClick={() => { addModule(mod.id); setDrawerOpen(false); }} icon={EyeIcon}>
                                        Show
                                    </Button>
                                </div>
                            ))}
                        {!layout.hidden.length && <p className={'text-sm text-slate-300'}>No hidden modules.</p>}
                    </div>
                </Dialog>
            )}
        </ConsoleStatsProvider>
    );
};

export { defaultLayout, modules };
export default ConsoleWorkspace;
