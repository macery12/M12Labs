import http from '@/api/http';

export const handleQuery = (server: string, query: string, signal?: AbortSignal): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${server}/ai`, { query, stream: true }, { signal })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const handleQueryStream = (
    server: string,
    query: string,
    onChunk: (chunk: string) => void,
    onComplete: () => void,
    onError: (error: Error) => void,
    signal?: AbortSignal
): void => {
    fetch(`/api/client/servers/${server}/ai`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ query, stream: true }),
        signal,
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            if (!response.body) {
                throw new Error('Response body is null');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            const readStream = (): Promise<void> => {
                return reader.read().then(({ done, value }) => {
                    if (done) {
                        onComplete();
                        return;
                    }

                    const chunk = decoder.decode(value, { stream: true });
                    const lines = chunk.split('\n');

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.substring(6);
                            if (data === '[DONE]') {
                                onComplete();
                                return;
                            }
                            try {
                                const parsed = JSON.parse(data);
                                if (parsed.content) {
                                    onChunk(parsed.content);
                                } else if (parsed.error) {
                                    onError(new Error(parsed.error));
                                    return;
                                }
                            } catch (e) {
                                // Ignore parse errors for incomplete chunks
                            }
                        }
                    }

                    return readStream();
                });
            };

            return readStream();
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                // Request was cancelled
                return;
            }
            onError(error);
        });
};
