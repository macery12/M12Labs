import stripAnsi from 'strip-ansi';
import { useEffect, useState, useRef } from 'react';
import { ServerContext } from '@/state/server';
import { Button } from '@/elements/button';
import { SparklesIcon } from '@heroicons/react/outline';
import { SocketEvent } from '@server/events';
import Dialog from '@/elements/dialog/Dialog';
import Spinner from '@/elements/Spinner';
import { handleQueryStream } from '@/api/routes/server/ai';
import { useStoreState } from '@/state/hooks';

type Visibility = 'none' | 'button' | 'dialog';

export default () => {
    const [log, setLog] = useState<string[]>([]);
    const [response, setResponse] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(false);
    const [visible, setVisible] = useState<Visibility>('none');
    const abortControllerRef = useRef<AbortController | null>(null);

    const isEnabled = useStoreState(state => state.everest.data!.ai.enabled);
    const status = ServerContext.useStoreState(state => state.status.value);
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);

    // Cleanup on unmount or when dialog closes
    useEffect(() => {
        if (visible === 'none' && abortControllerRef.current) {
            abortControllerRef.current.abort();
            abortControllerRef.current = null;
            setResponse('');
        }
    }, [visible]);

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
        setVisible('none');
    };

    const submit = () => {
        setVisible('dialog');
        setLoading(true);
        setResponse('');

        const data = stripAnsi(log.map(it => it.replace('\r', '')).join('\n')) || '';

        // Cancel any existing request
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        // Create new abort controller
        abortControllerRef.current = new AbortController();

        handleQueryStream(
            uuid,
            data,
            chunk => {
                setResponse(prev => prev + chunk);
            },
            () => {
                setLoading(false);
                abortControllerRef.current = null;
            },
            error => {
                console.error('AI query error:', error);
                setResponse('Error: Failed to get AI response. Please try again.');
                setLoading(false);
                abortControllerRef.current = null;
            },
            abortControllerRef.current.signal,
        );
    };

    useEffect(() => {
        if (!connected || !instance || status === 'running') return;

        const listener = (line: string) => {
            setLog(prevLog => [...prevLog, line.startsWith('>') ? line.substring(1) : line]);

            if (line.toLowerCase().indexOf('detected server process in a crashed state') >= 0) {
                setVisible('button');
            }
        };

        instance.addListener(SocketEvent.CONSOLE_OUTPUT, listener);

        return () => {
            instance.removeListener(SocketEvent.CONSOLE_OUTPUT, listener);
        };
    }, [connected, instance, status]);

    if (!isEnabled) return <></>;

    return visible === 'button' ? (
        <Button onClick={submit}>
            <SparklesIcon className={'mr-1 w-5'} /> Ask AI
        </Button>
    ) : visible === 'dialog' ? (
        <Dialog
            open={visible === 'dialog'}
            onClose={() => {
                cancelRequest();
                setVisible('none');
            }}
            title={'Server Assistant'}
        >
            {loading ? (
                <div className={'space-y-4'}>
                    <Spinner centered />
                    {response && (
                        <div className={'overflow-x-hidden whitespace-pre-wrap rounded-lg bg-black/50 p-3 text-sm'}>
                            {response}
                        </div>
                    )}
                    <div className={'flex justify-center'}>
                        <Button variant={'secondary'} size={'sm'} onClick={cancelRequest}>
                            Cancel Request
                        </Button>
                    </div>
                </div>
            ) : response ? (
                <div className={'overflow-x-hidden whitespace-pre-wrap rounded-lg bg-black/50 p-3 text-sm'}>
                    {response}
                </div>
            ) : (
                <div className={'text-center text-red-400'}>Error: No response received</div>
            )}
        </Dialog>
    ) : (
        <></>
    );
};
