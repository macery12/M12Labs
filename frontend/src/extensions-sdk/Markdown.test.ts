import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { Markdown } from './Markdown';

describe('Markdown', () => {
    it('does not execute raw HTML or fetch remote images', () => {
        const html = renderToStaticMarkup(createElement(Markdown, {
            content: '<script>alert(1)</script>\n\n![tracking pixel](https://example.test/pixel.gif)',
        }));

        expect(html).not.toContain('<script>');
        expect(html).not.toContain('<img');
        expect(html).not.toContain('pixel.gif');
        expect(html).toContain('tracking pixel');
    });

    it('drops unsafe link protocols', () => {
        const html = renderToStaticMarkup(createElement(Markdown, {
            content: '[unsafe](javascript:alert(1))',
        }));

        expect(html).not.toContain('javascript:');
        expect(html).toContain('unsafe');
    });

    it('renders the chat-facing GFM subset without a parser package', () => {
        const html = renderToStaticMarkup(createElement(Markdown, {
            content: '# Result\n\n**bold** and `code`\n\n- [x] done\n\n| Name | Value |\n| --- | ---: |\n| item | 2 |\n\n```json\n{"ok":true}\n```',
        }));

        expect(html).toContain('<h3');
        expect(html).toContain('<strong>bold</strong>');
        expect(html).toContain('type="checkbox"');
        expect(html).toContain('<table');
        expect(html).toContain('<pre');
        expect(html).toContain('language-json');
    });
});
