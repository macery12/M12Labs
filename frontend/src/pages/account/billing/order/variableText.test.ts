import { describe, expect, it } from 'vitest';
import { checkoutDescription } from './variableText';

describe('checkoutDescription', () => {
    it('drops the reinstall instruction that only makes sense after purchase', () => {
        expect(
            checkoutDescription(
                'The version of Minecraft Vanilla to install. Use "latest" to install the latest version. Go to Settings > Reinstall Server to apply.',
            ),
        ).toBe('The version of Minecraft Vanilla to install. Use "latest" to install the latest version.');
    });

    it('leaves other descriptions untouched', () => {
        expect(checkoutDescription('The name of the server jarfile to run the server with.')).toBe(
            'The name of the server jarfile to run the server with.',
        );
    });

    it('returns an empty string when the whole description was about reinstalling', () => {
        expect(checkoutDescription('Reinstall the server to apply.')).toBe('');
    });
});
