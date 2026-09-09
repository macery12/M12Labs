import { describe, expect, it } from 'vitest';
import { firstError } from './apiError';

const axiosError = (data: unknown) => ({
    isAxiosError: true,
    response: { data },
});

describe('firstError', () => {
    it('returns actionable JSON:API error detail', () => {
        expect(
            firstError(
                axiosError({
                    errors: [
                        {
                            detail:
                                'M12Labs cannot start the build. Run sudo chown -R www-data:www-data /var/www/m12labs.',
                        },
                    ],
                }),
            ),
        ).toContain('sudo chown -R www-data:www-data');
    });

    it('supports flat error payloads used by lifecycle conflicts', () => {
        expect(firstError(axiosError({ error: 'Files were modified after installation.' }))).toBe(
            'Files were modified after installation.',
        );
    });

    it('returns undefined when the response has no user-facing message', () => {
        expect(firstError(axiosError({ status: 'failed' }))).toBeUndefined();
    });
});
