import http from '@/api/http';

export interface Donation {
    id: number;
    user_id: number;
    payment_intent_id: string;
    amount: number;
    currency: string;
    status: 'pending' | 'completed' | 'failed';
    message?: string;
    created_at: string;
    updated_at: string;
}

export interface DonationIntent {
    id: string;
    secret: string;
}

export const getStripeKey = (): Promise<{ key: string }> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/donations/key')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const createDonationIntent = (amount: number, message?: string): Promise<DonationIntent> => {
    return new Promise((resolve, reject) => {
        http.post('/api/client/donations/intent', { amount, message })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const completeDonation = (intent: string): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post('/api/client/donations/complete', { intent })
            .then(() => resolve())
            .catch(reject);
    });
};

export const getDonations = (): Promise<{ data: Donation[] }> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/donations')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
