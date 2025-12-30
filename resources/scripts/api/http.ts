import axios, { AxiosInstance } from 'axios';

const http: AxiosInstance = axios.create({
    withCredentials: true,
    timeout: 20000,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

export default http;

/**
 * Structured error response interfaces for type-safe error handling.
 */
interface ApiErrorDetail {
    detail: string;
}

interface ApiErrorResponse {
    errors?: ApiErrorDetail[];
    error?: string;
}

interface HttpError {
    message?: string;
    response?: {
        data?: unknown;
    };
}

/**
 * Type guard to check if error has response structure.
 */
function isHttpError(error: unknown): error is HttpError {
    return error !== null && typeof error === 'object' && 'response' in error;
}

/**
 * Type guard to check if data matches ApiErrorResponse structure.
 */
function isApiErrorResponse(data: unknown): data is ApiErrorResponse {
    if (!data || typeof data !== 'object') {
        return false;
    }
    
    const maybeError = data as Record<string, unknown>;
    
    // Check for errors array
    if ('errors' in maybeError && Array.isArray(maybeError.errors)) {
        return maybeError.errors.every(
            (item) => item && typeof item === 'object' && 'detail' in item && typeof item.detail === 'string'
        );
    }
    
    // Check for single error string
    if ('error' in maybeError && typeof maybeError.error === 'string') {
        return true;
    }
    
    return false;
}

/**
 * Converts an error into a human readable response. Mostly just a generic helper to
 * make sure we display the message from the server back to the user if we can.
 */
export function httpErrorToHuman(error: unknown): string {
    if (!isHttpError(error)) {
        if (error && typeof error === 'object' && 'message' in error && typeof error.message === 'string') {
            return error.message;
        }
        return 'An unknown error occurred';
    }

    const { response } = error;
    if (!response?.data) {
        return error.message || 'An unknown error occurred';
    }

    let data = response.data;

    // Some non-JSON requests can still return the error as a JSON block. In those cases, attempt
    // to parse it into JSON so we can display an actual error.
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            // If parsing fails, return the original error message or a default
            return error.message || 'An unknown error occurred';
        }
    }

    // Type guard and extraction for API error response
    if (isApiErrorResponse(data)) {
        // Check for errors array with detail
        if (data.errors && data.errors[0]?.detail) {
            return data.errors[0].detail;
        }

        // Check for single error string (from Wings)
        if (data.error) {
            return data.error;
        }
    }

    return error.message || 'An unknown error occurred';
}

export interface FractalResponseData {
    object: string;
    attributes: {
        [k: string]: unknown;
        relationships?: Record<string, FractalResponseData | FractalResponseList | null | undefined>;
    };
    meta?: Record<string, unknown>;
}

export interface FractalResponseList {
    object: 'list';
    data: FractalResponseData[];
}

export interface FractalPaginatedResponse extends FractalResponseList {
    meta: {
        pagination: {
            total: number;
            count: number;
            /* eslint-disable camelcase */
            per_page: number;
            current_page: number;
            total_pages: number;
            /* eslint-enable camelcase */
        };
    };
}

export interface PaginatedResult<T> {
    items: T[];
    pagination: PaginationDataSet;
}

export interface PaginationDataSet {
    total: number;
    count: number;
    perPage: number;
    currentPage: number;
    totalPages: number;
}

export function getPaginationSet(data: {
    total: number;
    count: number;
    per_page: number;
    current_page: number;
    total_pages: number;
}): PaginationDataSet {
    return {
        total: data.total,
        count: data.count,
        perPage: data.per_page,
        currentPage: data.current_page,
        totalPages: data.total_pages,
    };
}

type QueryBuilderFilterValue = string | number | boolean | null;

export interface QueryBuilderParams<FilterKeys extends string = string, SortKeys extends string = string> {
    page?: number;
    filters?: {
        [K in FilterKeys]?: QueryBuilderFilterValue | Readonly<QueryBuilderFilterValue[]>;
    };
    sorts?: {
        [K in SortKeys]?: -1 | 0 | 1 | 'asc' | 'desc' | null;
    };
}

/**
 * Helper function that parses a data object provided and builds query parameters
 * for the Laravel Query Builder package automatically. This will apply sorts and
 * filters deterministically based on the provided values.
 */
export const withQueryBuilderParams = (data?: QueryBuilderParams): Record<string, unknown> => {
    if (!data) return {};

    const filters = Object.keys(data.filters || {}).reduce((obj, key) => {
        const value = data.filters?.[key];

        return !value || value === '' ? obj : { ...obj, [`filter[${key}]`]: value };
    }, {} as NonNullable<QueryBuilderParams['filters']>);

    const sorts = Object.keys(data.sorts || {}).reduce((arr, key) => {
        const value = data.sorts?.[key];
        if (!value || !['asc', 'desc', 1, -1].includes(value)) {
            return arr;
        }

        return [...arr, (value === -1 || value === 'desc' ? '-' : '') + key];
    }, [] as string[]);

    return {
        ...filters,
        sort: !sorts.length ? undefined : sorts.join(','),
        page: data.page,
    };
};
