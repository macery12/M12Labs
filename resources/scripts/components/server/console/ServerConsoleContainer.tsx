import { memo, useState } from 'react';
import isEqual from 'react-fast-compare';
import { Alert } from '@/elements/alert';
import Can from '@/elements/Can';
import { Button } from '@/elements/button';
import Spinner from '@/elements/Spinner';
import PowerButtons from '@server/console/PowerButtons';
import Features from '@feature/Features';
import { ServerContext, ServerStatus } from '@/state/server';
import classNames from 'classnames';
import { useStoreState } from '@/state/hooks';
import Pill from '@/elements/Pill';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCircle, faDownload, faSpinner } from '@fortawesome/free-solid-svg-icons';
import EditServerDialog from './EditServerDialog';
import PageContentBlock from '@/elements/PageContentBlock';
import ScopedAlert from '@account/ScopedAlert';
import ConsoleWorkspace from '@server/console/ConsoleWorkspace';
import { PencilAltIcon } from '@heroicons/react/outline';

export type PowerAction = 'start' | 'stop' | 'restart' | 'kill';

function statusToColor(status: ServerStatus): string {
    switch (status) {
        case 'running':
            return 'text-green-500';
        case 'offline':
            return 'text-red-500';
        default:
            return 'text-yellow-500';
    }
}

function ServerConsoleContainer() {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const name = ServerContext.useStoreState(state => state.server.data!.name);
    const description = ServerContext.useStoreState(state => state.server.data!.description);
    const isInstalling = ServerContext.useStoreState(state => state.server.isInstalling);
    const isTransferring = ServerContext.useStoreState(state => state.server.data!.isTransferring);
    const eggFeatures = ServerContext.useStoreState(state => state.server.data!.eggFeatures, isEqual);
    const isNodeUnderMaintenance = ServerContext.useStoreState(state => state.server.data!.isNodeUnderMaintenance);
    const status = ServerContext.useStoreState(state => state.status.value);
    const renewalDate = ServerContext.useStoreState(state => state.server.data!.renewalDate);
    const billingProductId = ServerContext.useStoreState(state => state.server.data!.billingProductId);
    const settings = useStoreState(state => state.everest.data!.billing);
    const [editMode, setEditMode] = useState(false);

    // Get configurable renewal settings
    const freeGraceDays = settings.renewal?.free_suspension_days || 7;

    // Calculate days until renewal (can be negative if overdue)
    const daysUntilRenewal = renewalDate
        ? Math.floor((new Date(renewalDate).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24))
        : null;

    // Show warning if within grace period (from expiration to grace period end)
    const showRenewalWarning =
        billingProductId &&
        daysUntilRenewal !== null &&
        daysUntilRenewal <= 0 &&
        Math.abs(daysUntilRenewal) <= freeGraceDays;

    return (
        <PageContentBlock title={'Server Console'} showFlashKey={'console:share'}>
            {showRenewalWarning && (
                <Alert type={'warning'} className={'mb-4'}>
                    Your server is {Math.abs(daysUntilRenewal!)} day{Math.abs(daysUntilRenewal!) !== 1 ? 's' : ''}{' '}
                    overdue for renewal. Please renew within {freeGraceDays} days to avoid permanent suspension. Your
                    server files and data will be preserved.
                </Alert>
            )}
            {(isNodeUnderMaintenance || isInstalling || isTransferring) && (
                <Alert type={'warning'} className={'mb-4'}>
                    {isNodeUnderMaintenance
                        ? 'The node of this server is currently under maintenance and all actions are unavailable.'
                        : isInstalling
                        ? 'This server is currently running its installation process and most actions are unavailable.'
                        : 'This server is currently being transferred to another node and all actions are unavailable.'}
                </Alert>
            )}
            {/* Server-scoped top-center alerts between header and console */}
            <ScopedAlert scope="server" position="top-center" />
            <div className={'mb-4 flex justify-between gap-4 rounded-lg bg-black/50 p-5'}>
                <div className={'hidden pr-4 sm:col-span-2 sm:block lg:col-span-3'}>
                    <div className={'flex items-center space-x-2'}>
                        <h1 className={'font-header text-2xl leading-relaxed text-slate-50 line-clamp-1'}>{name}</h1>
                        <Pill>
                            {isInstalling && (
                                <>
                                    <FontAwesomeIcon icon={faDownload} className={'my-auto mr-1'} />
                                    Installing
                                </>
                            )}
                            {isTransferring && (
                                <>
                                    <FontAwesomeIcon icon={faSpinner} className={'my-auto mr-1 animate-spin'} />
                                    Transferring
                                </>
                            )}
                            {!isInstalling && !isTransferring && (
                                <>
                                    <FontAwesomeIcon
                                        icon={faCircle}
                                        className={classNames('my-auto mr-1 w-2', statusToColor(status))}
                                    />
                                    {status}
                                </>
                            )}
                        </Pill>
                        <EditServerDialog />
                    </div>
                    <p className={'text-sm line-clamp-2'}>{description ?? uuid}</p>
                </div>
                <div className={'my-auto flex flex-wrap justify-end gap-2'}>
                    <Button.Dark onClick={() => setEditMode(s => !s)} icon={PencilAltIcon}>
                        {editMode ? 'Done Editing' : 'Edit layout'}
                    </Button.Dark>
                    <Can action={['control.start', 'control.stop', 'control.restart']} matchAny>
                        <PowerButtons className={' flex space-x-2 sm:justify-center'} />
                    </Can>
                </div>
            </div>
            <Spinner.Suspense>
                <ConsoleWorkspace editMode={editMode} onToggleEdit={() => setEditMode(s => !s)} />
            </Spinner.Suspense>
            <Features enabled={eggFeatures} />
        </PageContentBlock>
    );
}

export default memo(ServerConsoleContainer, isEqual);
