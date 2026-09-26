import http from '@/lib/http';
import type { OpenApiDoc } from '@/pages/admin/apidocs/openapi';

// Reader for the auto-generated OpenAPI document (Scramble) served at
// /api/openapi.json. Admin-gated server-side by the ApiDocsAccess middleware.
// Generation runs on the queue (it needs more memory than a web request has):
// the endpoint answers 202 while it runs, so the page polls until the spec is
// ready. A failed run is a 503 whose `message` is the reason. `refresh` drops
// the cached spec and queues a fresh run.

export const OPENAPI_SPEC_URL = '/api/openapi.json';
export const OPENAPI_DOCS_URL = '/api/docs';

export type ApiSpecResult =
    | { status: 'ready'; spec: OpenApiDoc }
    // Seconds since the run was queued, so the page can tell slow from stuck.
    | { status: 'generating'; waitedSeconds: number };

export async function getApiSpec(refresh = false): Promise<ApiSpecResult> {
    const response = await http.get(OPENAPI_SPEC_URL, {
        params: refresh ? { refresh: 1 } : undefined,
    });
    if (response.status === 202) {
        return { status: 'generating', waitedSeconds: Number(response.data?.waited_seconds ?? 0) };
    }
    return { status: 'ready', spec: response.data as OpenApiDoc };
}
