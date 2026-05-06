import { useCallback, useEffect, useRef, useState } from 'react';
import Modal from '@/elements/Modal';
import ExtensionScanStatus, { type ScanOutcome } from '@/components/extensions/ExtensionScanStatus';
import { scanExtension, type ScanReport } from '@/api/extensions/scanExtension';
import { httpErrorToHuman } from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faUpload,
    faCheckCircle,
    faTimesCircle,
    faExclamationTriangle,
} from '@fortawesome/free-solid-svg-icons';

type Step = 'upload' | 'scanning' | 'review' | 'done';

interface Props {
    visible: boolean;
    onDismissed: () => void;
    /** Called with the selected file when the user confirms installation after a passing scan. */
    onInstall?: (file: File, scanReport: ScanReport) => Promise<void>;
}

const ExtensionInstallModal = ({ visible, onDismissed, onInstall }: Props) => {
    const [step, setStep] = useState<Step>('upload');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [scanReport, setScanReport] = useState<ScanReport | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [installing, setInstalling] = useState(false);
    const [installError, setInstallError] = useState<string | null>(null);
    const [userConfirmedWarnings, setUserConfirmedWarnings] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const dragRef = useRef<HTMLDivElement>(null);

    // Reset state whenever the modal is opened.
    useEffect(() => {
        if (visible) {
            setStep('upload');
            setSelectedFile(null);
            setScanReport(null);
            setScanError(null);
            setInstalling(false);
            setInstallError(null);
            setUserConfirmedWarnings(false);
        }
    }, [visible]);

    // Automatically start scanning when a file is selected.
    useEffect(() => {
        if (!selectedFile) return;

        setStep('scanning');
        setScanError(null);
        setScanReport(null);
        setUserConfirmedWarnings(false);

        scanExtension(selectedFile)
            .then(report => {
                setScanReport(report);
                setStep('review');
            })
            .catch(err => {
                // When blocked the API returns 422 — the response body still has the report.
                const responseData = err?.response?.data;
                if (responseData && responseData.outcome === 'blocked') {
                    setScanReport(responseData as ScanReport);
                    setStep('review');
                } else {
                    setScanError(httpErrorToHuman(err));
                    setStep('upload');
                }
            });
    }, [selectedFile]);

    const handleFileChange = useCallback((file: File | null) => {
        if (!file) return;
        if (!file.name.toLowerCase().endsWith('.m12labsextension') && !file.name.toLowerCase().endsWith('.zip')) {
            setScanError('Please select a .M12LabsExtension file.');
            return;
        }
        setScanError(null);
        setSelectedFile(file);
    }, []);

    const onInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        handleFileChange(e.target.files?.[0] ?? null);
    };

    const onDrop = (e: React.DragEvent) => {
        e.preventDefault();
        handleFileChange(e.dataTransfer.files?.[0] ?? null);
    };

    const handleInstall = async () => {
        if (!selectedFile || !scanReport || !onInstall) return;

        setInstalling(true);
        setInstallError(null);

        try {
            await onInstall(selectedFile, scanReport);
            setStep('done');
        } catch (err) {
            setInstallError(httpErrorToHuman(err));
        } finally {
            setInstalling(false);
        }
    };

    const scanOutcome: ScanOutcome = (() => {
        if (step === 'scanning') return 'scanning';
        if (!scanReport) return 'pending';
        return scanReport.outcome as ScanOutcome;
    })();

    const canInstall =
        !!onInstall &&
        !installing &&
        scanReport !== null &&
        (scanReport.outcome === 'passed' || (scanReport.outcome === 'warned' && userConfirmedWarnings));

    return (
        <Modal visible={visible} onDismissed={onDismissed} dismissable={!installing}>
            <div className={'min-w-[320px] max-w-lg'}>
                <h2 className={'mb-4 text-lg font-semibold text-white'}>Install Extension</h2>

                {/* Upload area */}
                {(step === 'upload' || step === 'scanning') && (
                    <div
                        ref={dragRef}
                        onDragOver={e => e.preventDefault()}
                        onDrop={onDrop}
                        onClick={() => !selectedFile && inputRef.current?.click()}
                        className={`flex cursor-pointer flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 py-8 transition
                            ${step === 'scanning' ? 'cursor-not-allowed border-zinc-600 bg-zinc-800/50' : 'border-zinc-600 bg-zinc-800 hover:border-zinc-500 hover:bg-zinc-700/60'}`}
                    >
                        <FontAwesomeIcon icon={faUpload} className={'text-2xl text-neutral-500'} />
                        {selectedFile ? (
                            <p className={'text-center text-sm text-neutral-300'}>{selectedFile.name}</p>
                        ) : (
                            <>
                                <p className={'text-center text-sm text-neutral-400'}>
                                    Drag &amp; drop a <code className={'text-xs text-blue-400'}>.M12LabsExtension</code>{' '}
                                    file here, or click to browse.
                                </p>
                                <input
                                    ref={inputRef}
                                    type={'file'}
                                    accept={'.M12LabsExtension,.zip'}
                                    onChange={onInputChange}
                                    className={'hidden'}
                                />
                            </>
                        )}
                    </div>
                )}

                {/* Scan status */}
                {(step === 'scanning' || step === 'review') && (
                    <div className={'mt-4'}>
                        <ExtensionScanStatus
                            outcome={scanOutcome}
                            summary={scanReport?.summary}
                            findings={
                                scanReport
                                    ? {
                                          php_findings: scanReport.php_findings,
                                          js_findings: scanReport.js_findings,
                                          semgrep_findings: scanReport.semgrep_findings,
                                      }
                                    : undefined
                            }
                            scannedAt={scanReport?.scanned_at}
                            onProceed={
                                scanReport?.outcome === 'warned' && !userConfirmedWarnings
                                    ? () => setUserConfirmedWarnings(true)
                                    : undefined
                            }
                            onCancel={
                                scanReport?.outcome === 'blocked' || scanReport?.outcome === 'warned'
                                    ? () => {
                                          setStep('upload');
                                          setSelectedFile(null);
                                          setScanReport(null);
                                          setUserConfirmedWarnings(false);
                                      }
                                    : undefined
                            }
                        />
                    </div>
                )}

                {/* Done state */}
                {step === 'done' && (
                    <div className={'flex flex-col items-center gap-3 py-6 text-center'}>
                        <FontAwesomeIcon icon={faCheckCircle} className={'text-4xl text-green-400'} />
                        <p className={'font-medium text-white'}>Extension installed successfully!</p>
                        {scanReport?.outcome === 'warned' && (
                            <p className={'text-xs text-yellow-400'}>
                                <FontAwesomeIcon icon={faExclamationTriangle} className={'mr-1'} />
                                Installed with warnings — review the scan report.
                            </p>
                        )}
                    </div>
                )}

                {/* Error messages */}
                {scanError && (
                    <p className={'mt-3 flex items-center gap-1.5 text-sm text-red-400'}>
                        <FontAwesomeIcon icon={faTimesCircle} />
                        {scanError}
                    </p>
                )}
                {installError && (
                    <p className={'mt-3 flex items-center gap-1.5 text-sm text-red-400'}>
                        <FontAwesomeIcon icon={faTimesCircle} />
                        {installError}
                    </p>
                )}

                {/* Action buttons */}
                {step === 'review' && scanReport?.outcome !== 'blocked' && (
                    <div className={'mt-4 flex justify-end gap-2 border-t border-zinc-700 pt-4'}>
                        <button
                            onClick={onDismissed}
                            disabled={installing}
                            className={
                                'rounded px-3 py-1.5 text-sm text-neutral-400 transition hover:bg-zinc-700 hover:text-white disabled:opacity-50'
                            }
                        >
                            Cancel
                        </button>
                        {onInstall && (
                            <button
                                onClick={handleInstall}
                                disabled={!canInstall}
                                className={
                                    'rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50'
                                }
                            >
                                {installing ? 'Installing…' : 'Install'}
                            </button>
                        )}
                    </div>
                )}

                {step === 'done' && (
                    <div className={'mt-4 flex justify-end border-t border-zinc-700 pt-4'}>
                        <button
                            onClick={onDismissed}
                            className={'rounded bg-zinc-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-zinc-600'}
                        >
                            Close
                        </button>
                    </div>
                )}
            </div>
        </Modal>
    );
};

export default ExtensionInstallModal;
