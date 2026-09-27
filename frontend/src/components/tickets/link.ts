export interface TicketPrefill {
    title: string;
    message?: string;
    /** The server's numeric id (`internalId`), not its short identifier. */
    serverId?: number;
}

/**
 * A link that opens the New ticket form already filled in. Pages that have to
 * say "contact support" (a plan without databases, a server whose package was
 * deleted) link here so the player doesn't have to describe the problem from
 * scratch; the Tickets page reads the parameters once and strips them.
 */
export function newTicketLink({ title, message, serverId }: TicketPrefill): string {
    const params = new URLSearchParams({ new: '1', title });
    if (message) params.set('message', message);
    if (serverId !== undefined) params.set('server', String(serverId));
    return `/tickets?${params.toString()}`;
}

export function readTicketPrefill(params: URLSearchParams): TicketPrefill | null {
    if (params.get('new') !== '1') return null;
    const server = Number(params.get('server'));
    return {
        title: (params.get('title') ?? '').slice(0, 191),
        message: (params.get('message') ?? '').slice(0, 2000) || undefined,
        serverId: Number.isInteger(server) && server > 0 ? server : undefined,
    };
}
