import http from '@/api/http';
import { DonationUser } from '@/api/routes/account/donations';

export interface AdminDonation {
    id: number;
    user_id: number;
    payment_intent_id: string;
    amount: number;
    currency: string;
    status: 'pending' | 'completed' | 'failed';
    message?: string;
    created_at: string;
    updated_at: string;
    user?: DonationUser;
}

export interface PaginatedDonations {
    data: AdminDonation[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export const getAllDonations = (page = 1): Promise<PaginatedDonations> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/application/billing/donations?page=${page}`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
