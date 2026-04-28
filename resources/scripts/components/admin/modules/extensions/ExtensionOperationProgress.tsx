import { useEffect, useRef, useState } from 'react';
import classNames from 'classnames';
import { useStoreState } from '@/state/hooks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck } from '@fortawesome/free-solid-svg-icons';
import Spinner from '@/elements/Spinner';
import { InstallProgress, getInstallProgress } from '@/api/routes/admin/extensions';

const POLL_INTERVAL_MS = 1500;
/** How long (ms) to show the "completed" state before the banner dismisses. */
const COMPLETION_DISPLAY_DURATION = 5000;

type StepName =
    | 'downloading'
    | 'extracting'
    | 'validating'
    | 'copying'
    | 'optimizing'
    | 'building'
    | 'registering'
    | 'removing';

const INSTALL_STEPS: StepName[] = [
    'downloading',
    'extracting',
    'validating',
    'copying',
    'optimizing',
    'building',
    'registering',
];

const UNINSTALL_STEPS: StepName[] = ['validating', 'removing', 'optimizing', 'building', 'registering'];

const UPDATE_STEPS: StepName[] = [
    'downloading',
    'extracting',
    'validating',
    'removing',
    'copying',
    'optimizing',
    'building',
    'registering',
];

const STEP_LABELS: Record<string, string> = {
    downloading: 'Downloading',
    extracting: 'Extracting',
    validating: 'Validating',
    copying: 'Copying files',
    optimizing: 'Optimizing',
    building: 'Building panel',
    registering: 'Registering',
    removing: 'Removing files',
};

function getStepsForAction(action: string): StepName[] {
    if (action.includes('uninstall')) return UNINSTALL_STEPS;
    if (action.includes('update')) return UPDATE_STEPS;
    return INSTALL_STEPS;
}

function getActionVerb(action: string): string {
    if (action.includes('uninstall')) return 'Uninstalling';
    if (action.includes('update')) return 'Updating';
    return 'Installing';
}

type DisplayState = 'idle' | 'active' | 'completed';

/**
 * Persistent extension operation progress banner.
 *
 * Polls the /progress endpoint on mount so it immediately shows the current
 * state even after a page refresh. Handles single and batch operations.
 * Renders nothing when idle.
 */
export default () => {
    const { colors } = useStoreState(state => state.theme.data!);

    const [displayState, setDisplayState] = useState<DisplayState>('idle');
    const [progress, setProgress] = useState<InstallProgress | null>(null);

    const prevHadProgressRef = useRef(false);
    const lastProgressRef = useRef<InstallProgress | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const dismissTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const handleResult = (p: InstallProgress | null) => {
            if (p) {
                lastProgressRef.current = p;
                prevHadProgressRef.current = true;

                if (dismissTimerRef.current !== null) {
                    clearTimeout(dismissTimerRef.current);
                    dismissTimerRef.current = null;
                }

                setProgress(p);
                setDisplayState('active');
            } else if (prevHadProgressRef.current) {
                prevHadProgressRef.current = false;
                setProgress(null);

                if (lastProgressRef.current?.stage === 'completed') {
                    setDisplayState('completed');
                    dismissTimerRef.current = setTimeout(() => {
                        setDisplayState('idle');
                        dismissTimerRef.current = null;
                    }, COMPLETION_DISPLAY_DURATION);
                } else {
                    setDisplayState('idle');
                }
            }
        };

        // Check immediately on mount so a refresh mid-operation shows the banner right away.
        getInstallProgress().then(handleResult).catch(() => {});

        pollRef.current = setInterval(() => {
            getInstallProgress().then(handleResult).catch(() => {});
        }, POLL_INTERVAL_MS);

        return () => {
            if (pollRef.current !== null) clearInterval(pollRef.current);
            if (dismissTimerRef.current !== null) clearTimeout(dismissTimerRef.current);
        };
    }, []);

    if (displayState === 'idle') return null;

    const primary = colors.primary;
    const alpha = (hex: string, opacity: string) => `${hex}${opacity}`;

    // ─── Completed state ───────────────────────────────────────────────────────
    if (displayState === 'completed') {
        return (
            <div
                className={'mb-4 rounded-lg border p-4'}
                style={{ backgroundColor: alpha('#22c55e', '15'), borderColor: '#22c55e55' }}
            >
                <div className={'flex items-center gap-3'}>
                    <FontAwesomeIcon icon={faCheck} className={'text-green-400'} />
                    <span className={'text-sm font-semibold text-green-300'}>Operation completed</span>
                </div>
            </div>
        );
    }

    // ─── Active state ──────────────────────────────────────────────────────────
    if (!progress) return null;

    const isBatch = progress.action.startsWith('batch-');
    const verb = getActionVerb(progress.action);
    const steps = getStepsForAction(progress.action);
    const currentStageIndex = steps.indexOf(progress.stage as StepName);

    // Title line
    let titleSuffix: string;
    if (isBatch) {
        const total = progress.batch_total ?? 1;
        titleSuffix = `${total} extension${total === 1 ? '' : 's'}`;
    } else {
        titleSuffix = progress.extension_id;
    }

    // Sub-label for batch (extension ID + count)
    const batchSubLabel =
        isBatch && progress.batch_total
            ? `${progress.extension_id}  (${progress.batch_current ?? 1} of ${progress.batch_total})`
            : null;

    // Batch progress bar percentage
    const batchPercent =
        isBatch && progress.batch_total
            ? Math.round(((progress.batch_current ?? 1) / progress.batch_total) * 100)
            : null;

    return (
        <div
            className={'mb-4 rounded-lg border p-4'}
            style={{ backgroundColor: alpha(primary, '10'), borderColor: alpha(primary, '55') }}
        >
            {/* Header row */}
            <div className={'flex items-center gap-3'}>
                <Spinner size={'small'} />
                <div className={'flex-1 min-w-0'}>
                    <p className={'text-sm font-semibold text-neutral-100'}>
                        {verb} {titleSuffix}
                    </p>
                    {batchSubLabel && (
                        <p className={'mt-0.5 text-xs text-neutral-400'}>{batchSubLabel}</p>
                    )}
                </div>
                <span
                    className={'whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium'}
                    style={{ backgroundColor: alpha(primary, '20'), color: primary }}
                >
                    {STEP_LABELS[progress.stage] ?? progress.stage}
                    {progress.stage !== 'completed' && '…'}
                </span>
            </div>

            {/* Batch progress bar */}
            {batchPercent !== null && (
                <div className={'mt-3 h-1.5 rounded-full bg-neutral-700'}>
                    <div
                        className={'h-1.5 rounded-full transition-all duration-500'}
                        style={{ backgroundColor: primary, width: `${batchPercent}%` }}
                    />
                </div>
            )}

            {/* Step dots for single operations */}
            {!isBatch && steps.length > 0 && (
                <div className={'mt-3 flex flex-wrap items-center gap-x-2 gap-y-1'}>
                    {steps.map((step, index) => {
                        const isActive = step === progress.stage;
                        const isDone = currentStageIndex > index;
                        return (
                            <span key={step} className={'flex items-center gap-1'}>
                                <span
                                    className={classNames(
                                        'inline-block h-1.5 w-1.5 rounded-full transition-colors duration-300',
                                        isActive && 'bg-white',
                                        isDone && !isActive && 'bg-neutral-500',
                                        !isDone && !isActive && 'bg-neutral-700'
                                    )}
                                />
                                <span
                                    className={classNames(
                                        'text-xs transition-colors duration-300',
                                        isActive && 'text-neutral-100',
                                        isDone && !isActive && 'text-neutral-500',
                                        !isDone && !isActive && 'text-neutral-700'
                                    )}
                                >
                                    {STEP_LABELS[step]}
                                </span>
                            </span>
                        );
                    })}
                </div>
            )}
        </div>
    );
};
