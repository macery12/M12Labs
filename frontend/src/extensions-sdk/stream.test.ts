import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { createExtensionStream, type ExtensionStreamFrame } from './stream';
import type { ExtensionClient } from './client';

vi.mock('@/lib/globals', () => ({ readCsrfToken: () => 'test-token' }));

/**
 * The SSE wire format, which is the part of streaming that fails quietly.
 *
 * A byte boundary in the wrong place does not throw; it drops a frame, or
 * concatenates two, and the package sees a stream that merely seems unreliable.
 * So these tests feed the reader the chunk splits a real network produces
 * rather than one tidy string.
 */

const client: ExtensionClient = {
    url: (path: string) => `/api/client/servers/s1/extensions/ext/demo${path}`,
} as ExtensionClient;

/** A fetch whose body arrives in exactly the chunks given. */
function respondWith(chunks: string[], init: { status?: number; headers?: Record<string, string> } = {}) {
    const encoder = new TextEncoder();
    let index = 0;

    return vi.fn(async () => ({
        ok: (init.status ?? 200) < 400,
        status: init.status ?? 200,
        headers: new Headers(init.headers ?? {}),
        json: async () => ({}),
        body: {
            getReader: () => ({
                read: async () =>
                    index < chunks.length
                        ? { done: false, value: encoder.encode(chunks[index++]) }
                        : { done: true, value: undefined },
            }),
        },
    }));
}

async function collect(): Promise<ExtensionStreamFrame[]> {
    const frames: ExtensionStreamFrame[] = [];
    await createExtensionStream(client, '/events', { onFrame: frame => frames.push(frame) });

    return frames;
}

/** The single frame a test expects, asserting there is exactly one. */
async function onlyFrame(): Promise<ExtensionStreamFrame> {
    const frames = await collect();
    expect(frames).toHaveLength(1);

    return frames[0]!;
}

describe('createExtensionStream', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', respondWith([]));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('decodes a named event and parses its payload', async () => {
        vi.stubGlobal('fetch', respondWith(['event: line\ndata: {"text":"hello"}\n\n']));

        expect(await collect()).toEqual([{ event: 'line', data: { text: 'hello' }, id: null }]);
    });

    it('defaults an unnamed frame to the spec name', async () => {
        vi.stubGlobal('fetch', respondWith(['data: {"n":1}\n\n']));

        expect((await onlyFrame()).event).toBe('message');
    });

    // The whole reason this is not a one-line split: a frame that straddles two
    // network reads must arrive once and whole.
    it('reassembles a frame split across chunks', async () => {
        vi.stubGlobal('fetch', respondWith(['event: li', 'ne\ndata: {"te', 'xt":"hello"}\n', '\n']));

        expect(await collect()).toEqual([{ event: 'line', data: { text: 'hello' }, id: null }]);
    });

    it('keeps a multi-line payload as one frame, joined by newlines', async () => {
        vi.stubGlobal('fetch', respondWith(['data: one\ndata: two\n\n']));

        expect(await collect()).toEqual([{ event: 'message', data: 'one\ntwo', id: null }]);
    });

    it('carries the id through to the frame it precedes', async () => {
        vi.stubGlobal('fetch', respondWith(['id: 7\nevent: line\ndata: {}\n\n']));

        expect((await onlyFrame()).id).toBe('7');
    });

    it('drops comments but counts them as activity', async () => {
        vi.stubGlobal('fetch', respondWith([': keep-alive\n\ndata: {"n":1}\n\n']));

        const frames: ExtensionStreamFrame[] = [];
        const onActivity = vi.fn();
        await createExtensionStream(client, '/events', { onFrame: f => frames.push(f), onActivity });

        expect(frames).toHaveLength(1);
        expect(onActivity).toHaveBeenCalled();
    });

    it('reports the sentinel as a close rather than as a frame', async () => {
        vi.stubGlobal('fetch', respondWith(['data: {"n":1}\n\ndata: [DONE]\n\n']));

        const frames: ExtensionStreamFrame[] = [];
        const onClose = vi.fn();
        await createExtensionStream(client, '/events', { onFrame: f => frames.push(f), onClose });

        expect(frames).toHaveLength(1);
        expect(onClose).toHaveBeenCalledOnce();
    });

    // A stream of plain text lines should need no special casing at the call site.
    it('hands over a non-JSON payload as the raw string', async () => {
        vi.stubGlobal('fetch', respondWith(['data: not json at all\n\n']));

        expect((await onlyFrame()).data).toBe('not json at all');
    });

    // A proxy that normalises line endings would otherwise leave \r in the data.
    it('tolerates CRLF framing', async () => {
        vi.stubGlobal('fetch', respondWith(['event: line\r\ndata: {"n":1}\r\n\r\n']));

        expect(await collect()).toEqual([{ event: 'line', data: { n: 1 }, id: null }]);
    });

    it('surfaces the limits the server declared', async () => {
        vi.stubGlobal(
            'fetch',
            respondWith([], { headers: { 'X-Stream-Deadline-Seconds': '600', 'X-Stream-Keepalive-Seconds': '10' } }),
        );

        const onOpen = vi.fn();
        await createExtensionStream(client, '/events', { onFrame: () => {}, onOpen });

        expect(onOpen).toHaveBeenCalledWith({ deadlineSeconds: 600, keepAliveSeconds: 10 });
    });

    it('rejects a refusal with the panel’s own message', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 503,
                headers: new Headers(),
                json: async () => ({ errors: [{ detail: 'Too many live connections are open right now.' }] }),
            })),
        );

        await expect(createExtensionStream(client, '/events', { onFrame: () => {} })).rejects.toThrow(
            'Too many live connections are open right now.',
        );
    });

    // A crash or a proxy answers with something that is not the panel's
    // envelope, and "undefined" is not a message anyone can act on.
    it('falls back to a readable message when the failure is not JSON', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 502,
                headers: new Headers(),
                json: async () => {
                    throw new Error('not json');
                },
            })),
        );

        await expect(createExtensionStream(client, '/events', { onFrame: () => {} })).rejects.toThrow(
            /could not be opened \(502\)/,
        );
    });
});
