/*
 * Reading a server-sent event stream.
 *
 * axios cannot consume an incremental body, so this is fetch plus a reader.
 * That means CSRF, credentials and the panel's failure envelope have to be
 * handled here rather than inherited from the shared client — which is the
 * whole reason this is in the SDK: a package doing it itself would have to
 * import panel internals the installer refuses.
 *
 * What this parses is the SSE wire format and nothing above it. Frames are
 * handed over as `{ event, data, id }` with `data` already JSON-parsed; what a
 * frame means belongs to the package, exactly as it does on the server.
 */

import { readCsrfToken } from '@/lib/globals';
import type { ExtensionClient } from './client';

export interface ExtensionStreamFrame<T = unknown> {
    /** The SSE `event:` field. `message` when the server sent none. */
    event: string;
    data: T;
    /** The SSE `id:` field, for a server that numbers its frames. */
    id: string | null;
}

export interface ExtensionStreamOptions<T = unknown> {
    /** One decoded frame. Throwing from here aborts the stream. */
    onFrame: (frame: ExtensionStreamFrame<T>) => void;

    /** The stream is open. Headers have arrived; no frame has yet. */
    onOpen?: (info: ExtensionStreamInfo) => void;

    /**
     * The server sent its end-of-stream sentinel. Distinct from the promise
     * resolving, which also happens when the connection simply ended — a
     * package that needs to tell "finished" from "cut off" watches this.
     */
    onClose?: () => void;

    /**
     * Any frame or comment arrived, including keep-alives. Use it to drive an
     * idle timer: a stream that has stopped speaking is not the same as one
     * that closed, and only the client can notice the difference.
     */
    onActivity?: () => void;

    /** Request body. Omit for a GET. */
    body?: unknown;

    /** From TanStack Query's context, or your own controller. */
    signal?: AbortSignal;
}

export interface ExtensionStreamInfo {
    /** Seconds the server will hold this connection open at most. */
    deadlineSeconds: number | null;
    /** How often the server sends a keep-alive while it has nothing to say. */
    keepAliveSeconds: number | null;
}

const STREAM_ERRORS: Record<number, string> = {
    401: 'Your session expired. Sign in again and reopen this page.',
    403: 'You do not have permission to open this stream.',
    404: 'This stream is no longer available. Reload the page and try again.',
    419: 'Your session expired. Reload the page and try again.',
    429: 'Too many requests were sent in a short period. Wait a moment before trying again.',
    503: 'The panel is holding as many live connections as it can right now. Try again in a moment.',
};

/** The panel's JSON:API error envelope, when the failure carried one. */
async function failureMessage(response: Response): Promise<string> {
    try {
        const body = await response.json();
        const detail = body?.errors?.[0]?.detail;
        if (typeof detail === 'string' && detail.trim() !== '') return detail;
    } catch {
        // A failure that is not JSON is a proxy or a crash, not the panel
        // answering. The generic message below is the honest one.
    }

    return STREAM_ERRORS[response.status] ?? `The stream could not be opened (${response.status}).`;
}

/**
 * Open one of this extension's declared streams and read it to completion.
 *
 * Resolves when the stream ends, for any reason. Rejects if it never opened, or
 * if the body failed mid-flight; an abort rejects with the usual `AbortError`,
 * so a caller unmounting a page does not need to special-case it.
 *
 * ```ts
 * const client = createExtensionClient('my_extension', serverId);
 *
 * await createExtensionStream(client, '/build/log', {
 *     signal,
 *     onFrame: ({ event, data }) => {
 *         if (event === 'line') append((data as { text: string }).text);
 *     },
 * });
 * ```
 *
 * There is no reconnect here, deliberately. Resuming needs a cursor the server
 * agrees to honour, and whether one exists is a property of the endpoint rather
 * than of the transport — a stream that replays and one that does not would
 * otherwise look identical from the call site, and the difference is the whole
 * question of whether a dropped connection lost data.
 */
export async function createExtensionStream<T = unknown>(
    client: ExtensionClient,
    path: string,
    { onFrame, onOpen, onClose, onActivity, body, signal }: ExtensionStreamOptions<T>,
): Promise<void> {
    let response: Response;

    try {
        response = await fetch(client.url(path), {
            method: body === undefined ? 'GET' : 'POST',
            headers: {
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
                Accept: 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': readCsrfToken(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') throw error;

        throw new Error('The connection to the panel failed. Check your network and try again.', { cause: error });
    }

    if (!response.ok) throw new Error(await failureMessage(response));
    if (!response.body) throw new Error('Streaming is not supported by this browser.');

    onOpen?.({
        deadlineSeconds: numericHeader(response, 'X-Stream-Deadline-Seconds'),
        keepAliveSeconds: numericHeader(response, 'X-Stream-Keepalive-Seconds'),
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let frame: { event: string; data: string[]; id: string | null } = freshFrame();

    const flush = (): boolean => {
        if (frame.data.length === 0) {
            frame = freshFrame();
            return false;
        }

        const raw = frame.data.join('\n');
        const current = frame;
        frame = freshFrame();

        // The server's end-of-stream sentinel. Sent as data rather than as a
        // named event so a consumer that ignores event names still sees it.
        if (raw === '[DONE]') {
            onClose?.();
            return true;
        }

        onFrame({ event: current.event, data: safeParse<T>(raw), id: current.id });

        return false;
    };

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            // A trailing frame with no blank line after it. Ending mid-frame is
            // a truncated stream rather than a complete one, but the frame
            // itself is whole and dropping it would lose a final result.
            flush();
            return;
        }

        buffer += decoder.decode(value, { stream: true });

        let newlineAt = buffer.indexOf('\n');
        while (newlineAt !== -1) {
            // Only \n is stripped by the split; \r survives it, so a proxy that
            // normalises line endings would otherwise leave it inside the data.
            const line = buffer.slice(0, newlineAt).replace(/\r$/, '');
            buffer = buffer.slice(newlineAt + 1);
            newlineAt = buffer.indexOf('\n');

            // Counts as proof of life whatever it turns out to be, including
            // the keep-alive comments and the frame separators.
            onActivity?.();

            // A blank line ends the frame.
            if (line === '') {
                if (flush()) return;
                continue;
            }

            // Comments. The server sends these purely so proxies — and the
            // idle timer above — see traffic while the producer is thinking.
            if (line.startsWith(':')) continue;

            const colonAt = line.indexOf(':');
            const field = colonAt === -1 ? line : line.slice(0, colonAt);
            // One optional space after the colon is part of the framing, per
            // the spec, and is not data.
            let rest = colonAt === -1 ? '' : line.slice(colonAt + 1);
            if (rest.startsWith(' ')) rest = rest.slice(1);

            if (field === 'event') frame.event = rest;
            else if (field === 'data') frame.data.push(rest);
            else if (field === 'id') frame.id = rest;
            // `retry` and anything unknown are ignored: reconnection is the
            // caller's decision here, not the server's.
        }
    }
}

function freshFrame() {
    return { event: 'message', data: [] as string[], id: null as string | null };
}

function numericHeader(response: Response, name: string): number | null {
    const value = Number(response.headers.get(name));

    return Number.isFinite(value) && value > 0 ? value : null;
}

/**
 * A frame whose payload is not JSON is handed over as the raw string rather
 * than thrown away, so a stream of plain lines needs no special casing.
 */
function safeParse<T>(raw: string): T {
    try {
        return JSON.parse(raw) as T;
    } catch {
        return raw as unknown as T;
    }
}
