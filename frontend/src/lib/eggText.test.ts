import { describe, expect, it } from 'vitest';
import { withoutReinstallHint } from './eggText';

describe('withoutReinstallHint', () => {
    it('drops the reinstall instruction that only makes sense after purchase', () => {
        expect(
            withoutReinstallHint(
                'The version of Minecraft Vanilla to install. Use "latest" to install the latest version. Go to Settings > Reinstall Server to apply.',
            ),
        ).toBe('The version of Minecraft Vanilla to install. Use "latest" to install the latest version.');
    });

    it('leaves other descriptions untouched', () => {
        expect(withoutReinstallHint('The name of the server jarfile to run the server with.')).toBe(
            'The name of the server jarfile to run the server with.',
        );
    });

    it('returns an empty string when the whole description was about reinstalling', () => {
        expect(withoutReinstallHint('Reinstall the server to apply.')).toBe('');
    });
});
