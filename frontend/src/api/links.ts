import http from '@/lib/http';

// User-facing custom links (Client API). Mirrors V1's
// `resources/scripts/api/getLinks.ts`, which fed the sidebar footer.
//
// Deliberately typed apart from the admin `CustomLink`: the client transformer
// (Api/Client/LinkTransformer) returns only id/name/url — no `visible`, because
// the endpoint already filters to visible links. Sharing the admin mapper here
// would type `visible` as boolean while it is undefined at runtime.

// Which user sidebar a link renders in. Mirrors CustomLink::PLACEMENTS.
export type LinkPlacement = 'everywhere' | 'dashboard' | 'server';

export interface VisibleLink {
    id: number;
    name: string;
    url: string;
    placement: LinkPlacement;
}

interface FractalVisibleLink {
    attributes: { id: number; name: string; url: string; placement: LinkPlacement };
}

// GET /api/client/links — already in operator order.
export async function getVisibleLinks(): Promise<VisibleLink[]> {
    const { data } = await http.get('/api/client/links');
    return (data.data ?? []).map(({ attributes: a }: FractalVisibleLink) => ({
        id: a.id,
        name: a.name,
        url: a.url,
        placement: a.placement,
    }));
}
