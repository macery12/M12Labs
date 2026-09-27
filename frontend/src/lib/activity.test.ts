import { describe, expect, it } from 'vitest';
import { activityMessageId, activitySubject, collapseRepeats, describeActivity, humanizeEvent } from './activity';

describe('activityMessageId', () => {
    it('maps colons to dots and hyphens to underscores', () => {
        expect(activityMessageId('admin:api-keys:create')).toBe('activity.event.admin.api_keys.create');
        expect(activityMessageId('server:file.create-directory')).toBe('activity.event.server.file.create_directory');
        expect(activityMessageId('auth:success')).toBe('activity.event.auth.success');
    });
});

describe('activitySubject', () => {
    it('prefers the most specific property', () => {
        expect(activitySubject({ extension_id: 'ai', key: 'api_key', via: 'ai' })).toBe('ai');
        expect(activitySubject({ file: '/server.properties', context: 'client' })).toBe('/server.properties');
    });

    it('reads the name out of a logged model', () => {
        expect(activitySubject({ user: { id: 12, username: 'aitesting' }, new_data: {} })).toBe('aitesting');
        expect(activitySubject({ egg: { name: 'Paper' }, nest: { name: 'Minecraft' } })).toBe('Paper');
    });

    it('ignores redacted, empty and oversized values', () => {
        expect(activitySubject({ name: '[hidden]' })).toBeNull();
        expect(activitySubject({ name: '   ' })).toBeNull();
        expect(activitySubject({ command: 'x'.repeat(81) })).toBeNull();
        expect(activitySubject({ ip: '203.0.113.9', useragent: 'Mozilla' })).toBeNull();
        expect(activitySubject(undefined)).toBeNull();
    });
});

describe('humanizeEvent', () => {
    it('drops the scope and sentence-cases the rest', () => {
        expect(humanizeEvent('server:ai.assist.escalate')).toBe('AI assist escalate');
        expect(humanizeEvent('admin:custom-domains:update-settings')).toBe('Custom domains update settings');
    });

    it('keeps an unknown prefix, since it may be the only meaningful word', () => {
        expect(humanizeEvent('tools')).toBe('Tools');
        expect(humanizeEvent('widget:sync')).toBe('Widget sync');
    });
});

describe('describeActivity', () => {
    // No catalog is loaded under vitest, so every event takes the unlabelled path.
    it('falls back to the description, then the humanized event', () => {
        expect(describeActivity({ event: 'ext:ai:update', description: 'M12Labs-AI settings were updated' })).toBe(
            'M12Labs-AI settings were updated',
        );
        expect(describeActivity({ event: 'server:ai.assist.start', description: null })).toBe('AI assist start');
    });
});

describe('collapseRepeats', () => {
    const key = (s: string) => (s.startsWith('!') ? null : s);

    it('merges adjacent repeats only', () => {
        expect(collapseRepeats(['a', 'a', 'b', 'a'], key)).toEqual([
            { entry: 'a', count: 2 },
            { entry: 'b', count: 1 },
            { entry: 'a', count: 1 },
        ]);
    });

    it('never merges entries without a key', () => {
        expect(collapseRepeats(['!x', '!x'], key)).toEqual([
            { entry: '!x', count: 1 },
            { entry: '!x', count: 1 },
        ]);
    });
});
