import { describe, expect, it } from 'vitest';
import { collectCopy, duplicateNames, looksLikePlaceholder } from './contentChecks';

describe('looksLikePlaceholder', () => {
    it('flags the filler that went live on testpanel', () => {
        for (const t of ['PRICING HEADLINE', 'THIS IS A TESTEMONIAL MESSAGE', 'THIS IS A QUESTION', 'asds', 'aa', 'sdasd']) {
            expect(looksLikePlaceholder(t), t).toBe(true);
        }
    });

    it('flags stock filler phrases', () => {
        expect(looksLikePlaceholder('Lorem ipsum dolor sit amet')).toBe(true);
        expect(looksLikePlaceholder('This is a placeholder')).toBe(true);
        expect(looksLikePlaceholder('TODO: write this')).toBe(true);
    });

    it('leaves real copy alone', () => {
        for (const t of [
            'One console for every server you run.',
            'Game servers that start in seconds',
            'FAQ',
            'SFTP access',
            'Is there a free trial?',
            'Add',
            'Dash',
            'Salad',
            'Glass',
            '',
            '   ',
        ]) {
            expect(looksLikePlaceholder(t), t).toBe(false);
        }
        expect(looksLikePlaceholder(null)).toBe(false);
    });
});

describe('duplicateNames', () => {
    it('finds repeated names regardless of case and spacing', () => {
        expect(duplicateNames(['Minecraft Starter', 'Minecraft Basic', 'minecraft  starter'])).toEqual(['Minecraft Starter']);
    });

    it('returns nothing when every name is unique', () => {
        expect(duplicateNames(['A', 'B', ''])).toEqual([]);
    });
});

describe('collectCopy', () => {
    it('gathers nested strings but skips links, images and icons', () => {
        expect(
            collectCopy({
                title: 'Hi',
                primaryCta: { label: 'Go', href: '/x' },
                backgroundImage: 'https://img',
                items: [{ icon: 'Gauge', title: 'Fast', body: 'Very' }],
                categoryIds: [1, 2],
            }),
        ).toEqual(['Hi', 'Go', 'Fast', 'Very']);
    });
});
