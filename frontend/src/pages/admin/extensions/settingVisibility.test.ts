import { describe, expect, it } from 'vitest';
import type { ExtensionSettingField } from '@/api/extensions';
import { isVisible } from './settingVisibility';

const schema: ExtensionSettingField[] = [
    { key: 'provider', label: 'provider', type: 'select', default: 'ollama' },
    { key: 'budget_enforce', label: 'budget_enforce', type: 'boolean', default: false },
    { key: 'slots', label: 'slots', type: 'number' },
];

const selfHosted = { any: [{ setting: 'provider', equals: 'ollama' }, { setting: 'provider', equals: 'openai_compatible' }] };

describe('isVisible', () => {
    it('shows a field with no condition', () => {
        expect(isVisible(undefined, schema, {})).toBe(true);
    });

    it('follows the unsaved form, falling back to the declared default', () => {
        expect(isVisible(selfHosted, schema, {})).toBe(true);
        expect(isVisible(selfHosted, schema, { provider: 'anthropic' })).toBe(false);
        expect(isVisible(selfHosted, schema, { provider: 'openai_compatible' })).toBe(true);
    });

    it('requires every `all` predicate', () => {
        const condition = { all: [{ setting: 'budget_enforce', equals: true }] };

        expect(isVisible(condition, schema, { budget_enforce: false })).toBe(false);
        expect(isVisible(condition, schema, { budget_enforce: true })).toBe(true);
    });

    it('treats an empty string as not configured', () => {
        const condition = { all: [{ setting: 'slots', configured: true }] };

        expect(isVisible(condition, schema, { slots: '' })).toBe(false);
        expect(isVisible(condition, schema, { slots: 0 })).toBe(true);
    });

    it('does not hide a field behind one the drawer cannot render', () => {
        expect(isVisible({ all: [{ setting: 'mode', equals: 'ollama' }] }, schema, {})).toBe(true);
    });
});
