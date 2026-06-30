import http from '@/lib/http';

// Minimal user list for the manual server builder's owner picker.
// Backed by /api/application/users (Fractal collection of UserTransformer).

export interface AdminUser {
    id: number;
    username: string;
    email: string;
}

export async function getUsers(search?: string): Promise<AdminUser[]> {
    const params: Record<string, unknown> = { per_page: 100 };
    if (search) params['filter[email]'] = search;
    const { data } = await http.get('/api/application/users', { params });
    return (data.data ?? []).map((row: any) => {
        const a = row.attributes ?? row;
        return { id: a.id, username: a.username, email: a.email };
    });
}
