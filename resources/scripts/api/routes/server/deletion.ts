import http from '@/api/http';

export const scheduleDeletion = (serverUuid: string): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${serverUuid}/deletion/schedule`)
            .then(() => resolve())
            .catch(reject);
    });
};

export const cancelDeletion = (serverUuid: string): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${serverUuid}/deletion/cancel`)
            .then(() => resolve())
            .catch(reject);
    });
};
