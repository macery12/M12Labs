import { describe, expect, it } from 'vitest';
import { slotDeclarations } from './slots';

function manifest(
    id: string,
    slots?: Array<{ name: string; entry: string; order: number; requiredServerPermission?: string }>,
) {
    return {
        id,
        version: '1.0.0',
        icon: 'puzzle',
        server: [],
        admin: [],
        slots,
    };
}

describe('extension frontend slot declarations', () => {
    it('keeps older generated manifests with no slots compatible', () => {
        expect(slotDeclarations({ '../packages/old/extension.pages.json': manifest('old') })).toEqual([]);
    });

    it('orders contributions by order and then package id', () => {
        const declarations = slotDeclarations({
            '../packages/zeta/extension.pages.json': manifest('zeta', [
                { name: 'server-layout.banner', entry: 'notice', order: 10 },
            ]),
            '../packages/alpha/extension.pages.json': manifest('alpha', [
                { name: 'server-layout.overlay', entry: 'drawer', order: 10 },
            ]),
            '../packages/first/extension.pages.json': manifest('first', [
                { name: 'server-layout.banner', entry: 'urgent', order: 1 },
            ]),
        });

        expect(declarations.map(({ manifest: item, slot }) => `${item.id}:${slot.entry}`)).toEqual([
            'first:urgent',
            'alpha:drawer',
            'zeta:notice',
        ]);
    });

    it('drops an edited unknown slot or unsafe entry instead of guessing a mount', () => {
        const declarations = slotDeclarations({
            '../packages/demo/extension.pages.json': manifest('demo', [
                { name: 'checkout.payment', entry: 'upsell', order: 1 },
                { name: 'server-layout.overlay', entry: '../escape', order: 2 },
                {
                    name: 'server-layout.banner',
                    entry: 'bad-permission',
                    order: 2,
                    requiredServerPermission: 42 as unknown as string,
                },
                { name: 'server-layout.overlay', entry: 'safe-drawer', order: 3 },
            ]),
        });

        expect(declarations).toHaveLength(1);
        expect(declarations[0]!.slot.entry).toBe('safe-drawer');
    });
});
