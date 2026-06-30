import http from '@/lib/http';

export interface ActivityEntry {
    id: string;
    event: string;
    description: string | null;
    ip: string | null;
    timestamp: string;
}

// GET /api/client/account/activity — recent account activity (Fractal list).
export async function getAccountActivity(): Promise<ActivityEntry[]> {
    const { data } = await http.get('/api/client/account/activity', { params: { per_page: 8 } });
    return (data.data ?? []).map((row: { attributes: Omit<ActivityEntry, 'id'> & { id?: string } }, i: number) => ({
        id: String(row.attributes.id ?? i),
        event: row.attributes.event,
        description: row.attributes.description ?? null,
        ip: row.attributes.ip ?? null,
        timestamp: row.attributes.timestamp,
    }));
}
