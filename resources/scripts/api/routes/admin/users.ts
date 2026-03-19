import useSWR from 'swr';
import type { PaginatedResult, QueryBuilderParams } from '@/api/http';
import http, { getPaginationSet, withQueryBuilderParams } from '@/api/http';
import type { User } from '@definitions/admin';
import { Transformers } from '@definitions/admin';
import { createContext } from '@/api';
import { useContext } from 'react';

export interface UpdateUserValues {
    externalId: string;
    username: string;
    email: string;
    password: string;
    admin_role_id: number | null;
    rootAdmin: boolean;
    state: string;
}

const filters = ['id', 'uuid', 'external_id', 'username', 'email'] as const;
export type Filters = (typeof filters)[number];

export interface RealFilters {
    id?: number;
    username?: string;
    email?: string;
    root_admin?: boolean;
    use_totp?: boolean;
    created_at?: Date;
}

export const Context = createContext<RealFilters>();

const useGetUsers = (include: string[] = []) => {
    const { page, filters, sort, sortDirection } = useContext(Context);

    const params = {};
    if (filters !== null) {
        Object.keys(filters).forEach(key => {
            // @ts-expect-error todo
            params['filter[' + key + ']'] = filters[key];
        });
    }

    if (sort !== null) {
        // @ts-expect-error todo
        params.sort = (sortDirection ? '-' : '') + sort;
    }

    return useSWR<PaginatedResult<User>>(['users', page, filters, sort, sortDirection], async () => {
        const { data } = await http.get('/api/application/users', {
            params: { include: include.join(','), page, ...params },
        });

        return {
            items: (data.data || []).map(Transformers.toUser),
            pagination: getPaginationSet(data.meta.pagination),
        };
    });
};

const getUser = (id: number, include: string[] = []): Promise<User> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/application/users/${id}`, { params: { include: include.join(',') } })
            .then(({ data }) => resolve(Transformers.toUser(data)))
            .catch(reject);
    });
};

export interface UserSearchOptions {
    query?: string;
    limit?: number;
    page?: number;
    signal?: AbortSignal;
}

type LegacySearchParams = QueryBuilderParams<'username' | 'email'>;
type SearchUsersInput = UserSearchOptions | LegacySearchParams;

export const DEFAULT_USER_SEARCH_LIMIT = 25;

const buildSearchParams = ({ limit = DEFAULT_USER_SEARCH_LIMIT, ...options }: SearchUsersInput) => {
    if ('filters' in options || 'sorts' in options) {
        return {
            per_page: limit,
            ...withQueryBuilderParams(options as LegacySearchParams),
        };
    }

    const modern = options as UserSearchOptions;
    return {
        per_page: limit,
        ...withQueryBuilderParams({
            page: modern.page,
            filters: modern.query ? { '*': modern.query } : undefined,
            sorts: { username: 'asc' },
        }),
    };
};

const searchUsersPaginated = async (options: SearchUsersInput = {}): Promise<PaginatedResult<User>> => {
    const { signal } = options as UserSearchOptions;
    const params = buildSearchParams(options);

    const { data } = await http.get('/api/application/users', {
        params,
        signal,
    });

    return {
        items: data.data.map(Transformers.toUser),
        pagination: getPaginationSet(data.meta.pagination),
    };
};

const searchUserAccounts = async (options: SearchUsersInput = {}): Promise<User[]> => {
    const { items } = await searchUsersPaginated(options);
    return items;
};

const createUser = (values: UpdateUserValues, include: string[] = []): Promise<User> => {
    const data = {};
    Object.keys(values).forEach(k => {
        // @ts-expect-error todo
        data[k.replace(/[A-Z]/g, l => `_${l.toLowerCase()}`)] = values[k];
    });

    return new Promise((resolve, reject) => {
        http.post('/api/application/users', data, { params: { include: include.join(',') } })
            .then(({ data }) => resolve(Transformers.toUser(data)))
            .catch(reject);
    });
};

const updateUser = (id: number, values: Partial<UpdateUserValues>, include: string[] = []): Promise<User> => {
    const data = {};
    Object.keys(values).forEach(k => {
        // Don't set password if it is empty.
        if (k === 'password' && values[k] === '') {
            return;
        }
        // @ts-expect-error todo
        data[k.replace(/[A-Z]/g, l => `_${l.toLowerCase()}`)] = values[k];
    });
    return new Promise((resolve, reject) => {
        http.patch(`/api/application/users/${id}`, data, { params: { include: include.join(',') } })
            .then(({ data }) => resolve(Transformers.toUser(data)))
            .catch(reject);
    });
};

const suspendUser = (id: number): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/application/users/${id}/suspend`)
            .then(() => resolve())
            .catch(reject);
    });
};

const deleteUser = (id: number): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/application/users/${id}`)
            .then(() => resolve())
            .catch(reject);
    });
};

export {
    useGetUsers,
    getUser,
    searchUserAccounts,
    searchUsersPaginated,
    createUser,
    updateUser,
    suspendUser,
    deleteUser,
};
