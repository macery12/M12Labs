import { useCallback, useEffect, useRef, useState } from 'react';
import type { ExtensionClient } from './client';
import {
    createExtensionStream,
    type ExtensionStreamFrame,
    type ExtensionStreamInfo,
    type ExtensionStreamOptions,
} from './stream';

export type ExtensionStreamStatus = 'idle' | 'connecting' | 'open' | 'closed' | 'error';

export interface StartExtensionStreamOptions<T = unknown>
    extends Omit<ExtensionStreamOptions<T>, 'signal' | 'onOpen' | 'onClose'> {
    onOpen?: (info: ExtensionStreamInfo) => void;
    onClose?: () => void;
    onError?: (error: Error) => void;
}

export interface ExtensionStreamController<T = unknown> {
    status: ExtensionStreamStatus;
    error: Error | null;
    info: ExtensionStreamInfo | null;
    start: (path: string, options: StartExtensionStreamOptions<T>) => Promise<void>;
    cancel: () => void;
    reset: () => void;
}

function asError(error: unknown): Error {
    return error instanceof Error ? error : new Error('The stream failed unexpectedly.', { cause: error });
}

function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

/**
 * Lifecycle-safe controller around `createExtensionStream`.
 *
 * Starting a new stream cancels the previous one, unmounting always aborts the
 * active request, and state from an older request cannot overwrite a newer
 * request. The transport remains callback-based so packages do not need to
 * retain an unbounded array of frames in React state.
 */
export function useExtensionStream<T = unknown>(client: ExtensionClient): ExtensionStreamController<T> {
    const [status, setStatus] = useState<ExtensionStreamStatus>('idle');
    const [error, setError] = useState<Error | null>(null);
    const [info, setInfo] = useState<ExtensionStreamInfo | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const requestRef = useRef(0);
    const mountedRef = useRef(true);

    useEffect(() => {
        // React Strict Mode mounts, cleans up, and mounts effects once more in
        // development. Restore this flag in the setup phase so the second
        // mount remains live.
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
            // eslint-disable-next-line react-hooks/exhaustive-deps -- cleanup must invalidate whichever request is current at unmount, not the one current at setup
            requestRef.current++;
            abortRef.current?.abort();
            abortRef.current = null;
        };
    }, []);

    const cancel = useCallback(() => {
        requestRef.current++;
        abortRef.current?.abort();
        abortRef.current = null;
        if (mountedRef.current) {
            setStatus('idle');
            setError(null);
            setInfo(null);
        }
    }, []);

    const reset = useCallback(() => {
        if (abortRef.current || !mountedRef.current) return;
        setStatus('idle');
        setError(null);
        setInfo(null);
    }, []);

    const start = useCallback(
        async (path: string, options: StartExtensionStreamOptions<T>): Promise<void> => {
            if (!mountedRef.current) return;

            abortRef.current?.abort();
            const controller = new AbortController();
            const request = ++requestRef.current;
            abortRef.current = controller;

            if (mountedRef.current) {
                setStatus('connecting');
                setError(null);
                setInfo(null);
            }

            try {
                await createExtensionStream(client, path, {
                    body: options.body,
                    signal: controller.signal,
                    onFrame: (frame: ExtensionStreamFrame<T>) => {
                        if (request === requestRef.current) options.onFrame(frame);
                    },
                    onActivity: () => {
                        if (request === requestRef.current) options.onActivity?.();
                    },
                    onOpen: streamInfo => {
                        if (request !== requestRef.current || !mountedRef.current) return;
                        setInfo(streamInfo);
                        setStatus('open');
                        options.onOpen?.(streamInfo);
                    },
                    onClose: () => {
                        if (request === requestRef.current) options.onClose?.();
                    },
                });

                if (request === requestRef.current && mountedRef.current) setStatus('closed');
            } catch (caught) {
                if (request !== requestRef.current || isAbortError(caught)) return;
                const streamError = asError(caught);
                if (mountedRef.current) {
                    setError(streamError);
                    setStatus('error');
                }
                options.onError?.(streamError);
            } finally {
                if (request === requestRef.current) abortRef.current = null;
            }
        },
        [client],
    );

    return { status, error, info, start, cancel, reset };
}
