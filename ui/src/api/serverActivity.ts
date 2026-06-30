import http from '@/lib/http';
import type { ActivityEntry } from '@/api/activity';

// GET /api/client/servers/{id}/activity — recent activity scoped to one server.
export async function getServerActivity(id: string): Promise<ActivityEntry[]> {
    const { data } = await http.get(`/api/client/servers/${id}/activity`, { params: { per_page: 10 } });
    return (data.data ?? []).map((row: { attributes: Omit<ActivityEntry, 'id'> & { id?: string } }, i: number) => ({
        id: String(row.attributes.id ?? i),
        event: row.attributes.event,
        description: row.attributes.description ?? null,
        ip: row.attributes.ip ?? null,
        timestamp: row.attributes.timestamp,
    }));
}
