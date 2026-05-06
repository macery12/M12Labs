import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCheckCircle,
    faExclamationTriangle,
    faTimesCircle,
    faSpinner,
    faClock,
    faChevronDown,
    faChevronUp,
} from '@fortawesome/free-solid-svg-icons';
import type { ScanFinding, ScanSummary } from '@/api/extensions/scanExtension';

export type ScanOutcome = 'passed' | 'warned' | 'blocked' | 'pending' | 'scanning';

interface FindingSet {
    php_findings: ScanFinding[];
    js_findings: ScanFinding[];
    semgrep_findings: ScanFinding[];
}

interface Props {
    outcome: ScanOutcome;
    summary?: ScanSummary;
    findings?: FindingSet;
    scannedAt?: string;
    onProceed?: () => void;
    onCancel?: () => void;
}

const FindingRow = ({ finding }: { finding: ScanFinding }) => {
    const sev =
        typeof finding.severity === 'number'
            ? finding.severity >= 2
                ? 'ERROR'
                : 'WARNING'
            : String(finding.severity).toUpperCase();

    return (
        <div className={'flex items-start gap-2 rounded bg-zinc-900 px-3 py-2 text-xs'}>
            <span
                className={`mt-0.5 shrink-0 font-semibold ${sev === 'ERROR' ? 'text-red-400' : 'text-yellow-400'}`}
            >
                {sev}
            </span>
            <span className={'text-neutral-300'}>
                <span className={'font-medium text-white'}>{finding.file.split('/').pop()}</span>
                {finding.line > 0 && <span className={'text-neutral-500'}> :{finding.line}</span>}
                {' — '}
                {finding.message}
            </span>
        </div>
    );
};

const FindingsList = ({ findings }: { findings: FindingSet }) => {
    const all = [...findings.php_findings, ...findings.js_findings, ...findings.semgrep_findings];

    if (all.length === 0) return null;

    return (
        <div className={'mt-3 flex flex-col gap-1.5 max-h-56 overflow-y-auto'}>
            {all.map((f, i) => (
                <FindingRow key={i} finding={f} />
            ))}
        </div>
    );
};

const ExtensionScanStatus = ({ outcome, summary, findings, scannedAt, onProceed, onCancel }: Props) => {
    const [expanded, setExpanded] = useState(false);

    if (outcome === 'scanning') {
        return (
            <div className={'flex items-center gap-3 rounded-lg bg-zinc-800 px-4 py-3'}>
                <FontAwesomeIcon icon={faSpinner} spin className={'text-blue-400'} />
                <span className={'text-sm text-neutral-300'}>Scanning extension for security issues…</span>
            </div>
        );
    }

    if (outcome === 'pending') {
        return (
            <div className={'flex items-center gap-3 rounded-lg bg-zinc-800 px-4 py-3'}>
                <FontAwesomeIcon icon={faClock} className={'text-neutral-500'} />
                <span className={'text-sm text-neutral-400'}>Not yet scanned</span>
            </div>
        );
    }

    if (outcome === 'passed') {
        return (
            <div className={'flex items-center gap-3 rounded-lg bg-green-900/30 border border-green-700/40 px-4 py-3'}>
                <FontAwesomeIcon icon={faCheckCircle} className={'text-green-400'} />
                <div>
                    <p className={'text-sm font-medium text-green-300'}>Security scan passed</p>
                    {scannedAt && (
                        <p className={'mt-0.5 text-xs text-neutral-500'}>
                            Scanned {new Date(scannedAt).toLocaleString()}
                        </p>
                    )}
                </div>
            </div>
        );
    }

    if (outcome === 'warned') {
        return (
            <div className={'rounded-lg border border-yellow-600/40 bg-yellow-900/20 px-4 py-3'}>
                <div className={'flex items-start gap-3'}>
                    <FontAwesomeIcon icon={faExclamationTriangle} className={'mt-0.5 text-yellow-400'} />
                    <div className={'flex-1'}>
                        <div className={'flex items-center justify-between'}>
                            <p className={'text-sm font-medium text-yellow-300'}>
                                {summary?.warnings ?? 0} warning(s) found
                            </p>
                            {findings && (
                                <button
                                    onClick={() => setExpanded(v => !v)}
                                    className={'flex items-center gap-1 text-xs text-neutral-400 hover:text-white'}
                                >
                                    {expanded ? 'Hide' : 'Show'} details
                                    <FontAwesomeIcon icon={expanded ? faChevronUp : faChevronDown} className={'ml-1'} />
                                </button>
                            )}
                        </div>
                        {scannedAt && (
                            <p className={'mt-0.5 text-xs text-neutral-500'}>
                                Scanned {new Date(scannedAt).toLocaleString()}
                            </p>
                        )}
                        {expanded && findings && <FindingsList findings={findings} />}
                    </div>
                </div>
                {(onProceed || onCancel) && (
                    <div className={'mt-3 flex justify-end gap-2 border-t border-yellow-700/30 pt-3'}>
                        {onCancel && (
                            <button
                                onClick={onCancel}
                                className={
                                    'rounded px-3 py-1.5 text-xs text-neutral-400 transition hover:bg-zinc-700 hover:text-white'
                                }
                            >
                                Cancel
                            </button>
                        )}
                        {onProceed && (
                            <button
                                onClick={onProceed}
                                className={
                                    'rounded bg-yellow-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-yellow-500'
                                }
                            >
                                Install Anyway
                            </button>
                        )}
                    </div>
                )}
            </div>
        );
    }

    // blocked
    return (
        <div className={'rounded-lg border border-red-600/40 bg-red-900/20 px-4 py-3'}>
            <div className={'flex items-start gap-3'}>
                <FontAwesomeIcon icon={faTimesCircle} className={'mt-0.5 text-red-400'} />
                <div className={'flex-1'}>
                    <div className={'flex items-center justify-between'}>
                        <p className={'text-sm font-medium text-red-300'}>
                            Installation blocked — {summary?.high ?? 0} high-severity issue(s) found
                        </p>
                        {findings && (
                            <button
                                onClick={() => setExpanded(v => !v)}
                                className={'flex items-center gap-1 text-xs text-neutral-400 hover:text-white'}
                            >
                                {expanded ? 'Hide' : 'Show'} details
                                <FontAwesomeIcon icon={expanded ? faChevronUp : faChevronDown} className={'ml-1'} />
                            </button>
                        )}
                    </div>
                    {scannedAt && (
                        <p className={'mt-0.5 text-xs text-neutral-500'}>
                            Scanned {new Date(scannedAt).toLocaleString()}
                        </p>
                    )}
                    {expanded && findings && <FindingsList findings={findings} />}
                </div>
            </div>
            {onCancel && (
                <div className={'mt-3 flex justify-end border-t border-red-700/30 pt-3'}>
                    <button
                        onClick={onCancel}
                        className={
                            'rounded bg-red-700 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-red-600'
                        }
                    >
                        Cancel
                    </button>
                </div>
            )}
        </div>
    );
};

export default ExtensionScanStatus;
