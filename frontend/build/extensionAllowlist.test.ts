import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { readExtensionAllowlist, restrictPackageGlobs } from './extensionAllowlist';

describe('restrictPackageGlobs', () => {
    it('names only the allowed packages, keeping the glob options', () => {
        const code = "const m = import.meta.glob('../packages/*/extension.pages.json', { eager: true });";

        expect(restrictPackageGlobs(code, ['ai', 'custom_domains'])).toBe(
            "const m = import.meta.glob(['../packages/ai/extension.pages.json', '../packages/custom_domains/extension.pages.json'], { eager: true });",
        );
    });

    it('handles the deeper relative prefixes the page registries use', () => {
        const code = "import.meta.glob('../../../extensions/packages/*/pages/server/*.tsx')";

        expect(restrictPackageGlobs(code, ['ai'])).toBe(
            "import.meta.glob(['../../../extensions/packages/ai/pages/server/*.tsx'])",
        );
    });

    it('matches nothing when no package is installed', () => {
        expect(restrictPackageGlobs("import.meta.glob('../packages/*/slots/*.tsx')", [])).toBe(
            "import.meta.glob(['../packages/__no_installed_extensions__/slots/*.tsx'])",
        );
    });

    it('leaves unrelated globs alone', () => {
        const code = "import.meta.glob('./locales/*.json')";

        expect(restrictPackageGlobs(code, ['ai'])).toBe(code);
    });
});

describe('readExtensionAllowlist', () => {
    const file = (content: string) => {
        const path = join(mkdtempSync(join(tmpdir(), 'allowlist-')), 'installed.json');
        writeFileSync(path, content);
        return path;
    };

    it('is null without a file, so dev and CI builds include everything', () => {
        expect(readExtensionAllowlist(join(tmpdir(), 'no-such-allowlist.json'))).toBeNull();
    });

    it('reads, de-duplicates and sorts the ids', () => {
        expect(readExtensionAllowlist(file('{"ids":["custom_domains","ai","ai"]}'))).toEqual(['ai', 'custom_domains']);
    });

    it('refuses an id that could escape the glob', () => {
        expect(() => readExtensionAllowlist(file('{"ids":["ai\',evil"]}'))).toThrow();
    });
});
