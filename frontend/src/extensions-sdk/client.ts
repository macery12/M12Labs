import type { AxiosInstance, AxiosRequestConfig, AxiosResponse } from 'axios';
import http from '@/lib/http';
import { adminExtensionBase, clientExtensionBase, joinExtensionPath } from './paths';

/**
 * The panel response envelope (RespondsWithExtensionEnvelope). Admin endpoints
 * always return one of these two shapes.
 */
export interface ExtensionListEnvelope<T> {
    object: 'list';
    data: Array<{ object: string; attributes: T }>;
    meta?: Record<string, unknown>;
}

export interface ExtensionItemEnvelope<T> {
    object: string;
    attributes: T;
}

export interface ExtensionClient {
    /** Absolute URL for a path relative to this extension's namespace. */
    url(path: string): string;
    get<T>(path: string, config?: AxiosRequestConfig): Promise<T>;
    post<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T>;
    put<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T>;
    patch<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T>;
    delete<T>(path: string, config?: AxiosRequestConfig): Promise<T>;
    /** The raw axios instance, for the rare call that needs full control. */
    raw: AxiosInstance;
}

/**
 * Unwrap the panel's response envelope so callers work with plain data.
 *
 * A list envelope becomes `T[]`; an item envelope becomes `T`. Anything else is
 * returned untouched, so an endpoint that deliberately returns a bare object
 * still works.
 */
function unwrap<T>(response: AxiosResponse): T {
    const body = response.data;
    if (body && typeof body === 'object') {
        if (body.object === 'list' && Array.isArray(body.data)) {
            return body.data.map((row: { attributes?: unknown }) => row?.attributes ?? row) as T;
        }
        if ('attributes' in body) {
            return body.attributes as T;
        }
    }
    return body as T;
}

function build(base: string): ExtensionClient {
    const request = async <T>(method: string, path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> => {
        const response = await http.request({
            ...config,
            method,
            url: joinExtensionPath(base, path),
            data: body,
        });
        return unwrap<T>(response);
    };

    return {
        url: path => joinExtensionPath(base, path),
        get: (path, config) => request('get', path, undefined, config),
        post: (path, body, config) => request('post', path, body, config),
        put: (path, body, config) => request('put', path, body, config),
        patch: (path, body, config) => request('patch', path, body, config),
        delete: (path, config) => request('delete', path, undefined, config),
        raw: http,
    };
}

/**
 * Authenticated client for an extension's per-server API.
 *
 * Pass `signal` from TanStack Query's context on every call so an in-flight
 * request is aborted when the page unmounts:
 *
 *   useQuery({ queryFn: ({ signal }) => client.get('/records', { signal }) })
 */
export function createExtensionClient(extensionId: string, serverId: string): ExtensionClient {
    return build(clientExtensionBase(serverId, extensionId));
}

/** Authenticated client for an extension's admin API. */
export function createExtensionAdminClient(extensionId: string): ExtensionClient {
    return build(adminExtensionBase(extensionId));
}
