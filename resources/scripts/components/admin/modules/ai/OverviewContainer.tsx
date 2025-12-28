import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/ai/ToggleFeatureButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faChevronRight } from '@fortawesome/free-solid-svg-icons';
import { SparklesIcon, XCircleIcon } from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';
import { KeyboardEvent as ReactKeyboardEvent, useState, useRef, useEffect } from 'react';
import { handleQueryStream } from '@/api/routes/admin/ai/handleQuery';
import { useFlashKey } from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';

interface Props {
    primary: string;
    loading: boolean;
    result: string | undefined;
}

function DisplayMessage({ primary, result, loading }: Props) {
    if (result && result !== 'error') {
        return (
            <>
                <SparklesIcon className={'inline-flex h-4 w-4'} style={{ color: primary }} />
                <div className={'whitespace-pre-wrap'}>{result}</div>
            </>
        );
    }

    if (result && result === 'error') {
        return (
            <>
                <XCircleIcon className={'inline-flex h-4 w-4 text-red-400'} /> An error occurred. Please try again
                later.
            </>
        );
    }

    if (loading) {
        return (
            <>
                <Spinner className={'my-auto inline-flex'} size={'small'} />
                <span className={'ml-2 animate-pulse'}>...</span>
            </>
        );
    }

    return (
        <>
            <SparklesIcon className={'inline-flex h-4 w-4'} style={{ color: primary }} /> waiting for query
        </>
    );
}

export default () => {
    const [result, setResult] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(false);
    const [query, setQuery] = useState<string>('');
    const { primary } = useStoreState(s => s.theme.data!.colors);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:ai');
    const abortControllerRef = useRef<AbortController | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    const cancelRequest = () => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
            abortControllerRef.current = null;
        }
        setLoading(false);
    };

    const submit = () => {
        if (query.trim().length < 1) return;

        clearFlashes();
        setLoading(true);
        setResult('');

        // Cancel any existing request
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        // Create new abort controller
        abortControllerRef.current = new AbortController();

        handleQueryStream(
            query,
            (chunk) => {
                setResult(prev => prev + chunk);
            },
            () => {
                setLoading(false);
                abortControllerRef.current = null;
            },
            (error) => {
                setResult('error');
                setLoading(false);
                clearAndAddHttpError(error);
                abortControllerRef.current = null;
            },
            abortControllerRef.current.signal
        );
    };

    const handleKeyDown = (e: ReactKeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    return (
        <div className={'grid gap-4 lg:grid-cols-5'}>
            <div className={'col-span-3'}>
                <div className={'relative h-full min-h-[50vh] overflow-auto rounded-t bg-black shadow-xl'}>
                    <div className={'absolute top-0 left-0 w-full p-2 font-mono text-sm'}>
                        <DisplayMessage primary={primary} loading={loading} result={result || undefined} />
                    </div>
                </div>
                <div className={'flex w-full flex-col rounded-b bg-zinc-800 px-4 py-2'}>
                    <div className={'flex items-start'}>
                        <FontAwesomeIcon icon={faChevronRight} className={'mt-2 mr-4 flex-shrink-0'} />
                        <textarea
                            ref={textareaRef}
                            className={'flex-1 resize-none border-none bg-transparent font-mono text-sm focus:outline-none focus:ring-0'}
                            placeholder={'Ask Jexactyl AI a question (Shift+Enter for new line, Enter to send)'}
                            rows={3}
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            onKeyDown={handleKeyDown}
                            disabled={loading}
                        />
                    </div>
                    <div className={'mt-2 flex justify-end space-x-2'}>
                        {loading && (
                            <Button variant={'secondary'} size={'sm'} onClick={cancelRequest}>
                                Cancel
                            </Button>
                        )}
                        <Button size={'sm'} onClick={submit} disabled={loading || query.trim().length < 1}>
                            Send
                        </Button>
                    </div>
                </div>
            </div>
            <div className={'col-span-2 space-y-4'}>
                <Alert type={'warning'} className={'mt-16 md:mt-0'}>
                    Jexactyl AI uses OpenAI-compatible endpoints. Information provided could be inaccurate or outdated.
                    Use with caution!
                </Alert>
                <Alert type={'info'}>
                    API request limits depend on your AI provider. Check with your provider for rate limiting details.
                </Alert>
                <Alert type={'info'}>
                    <div className={'text-sm'}>
                        <strong>Streaming enabled:</strong> Responses stream in real-time to prevent timeouts.
                        You can cancel ongoing requests at any time.
                    </div>
                </Alert>
                <AdminBox title={'Disable Jexactyl AI'} className={'col-span-2 h-min'}>
                    Clicking the button below will disable Jexactyl AI for both clients and administrators. Your API key
                    will remain in the database unless you choose to delete it manually.
                    <div className={'mt-2 text-right'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
