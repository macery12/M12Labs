import { EditorView } from '@codemirror/view';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { tags as t } from '@lezer/highlight';

// Editor chrome themed against the V2 tokens so CodeMirror follows light/dark.
// The card around the editor owns the border and radius.
const chrome = EditorView.theme({
    '&': {
        height: '100%',
        backgroundColor: 'var(--color-surface-2)',
        color: 'var(--color-ink)',
        fontSize: '14px',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { overflow: 'auto', fontFamily: 'var(--font-mono)', lineHeight: '1.6' },
    '.cm-content': { padding: '10px 0' },
    '.cm-line': { padding: '0 16px 0 12px' },
    '.cm-gutters': {
        backgroundColor: 'var(--color-surface-2)',
        color: 'var(--color-ink-faint)',
        borderRight: '1px solid var(--color-border-strong)',
    },
    '.cm-lineNumbers .cm-gutterElement': { padding: '0 10px 0 14px', minWidth: '3ch' },
    '.cm-foldGutter .cm-gutterElement': { padding: '0 4px' },
    '.cm-activeLine': { backgroundColor: 'color-mix(in oklab, var(--color-ink) 4%, transparent)' },
    '.cm-activeLineGutter': { backgroundColor: 'transparent', color: 'var(--color-ink-muted)' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--color-ink)' },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, ::selection':
        { backgroundColor: 'color-mix(in oklab, var(--brand) 32%, transparent)' },
    '.cm-selectionMatch': { backgroundColor: 'color-mix(in oklab, var(--brand) 18%, transparent)' },
    '&.cm-focused .cm-matchingBracket': {
        backgroundColor: 'color-mix(in oklab, var(--color-ink) 12%, transparent)',
        outline: 'none',
    },
    '.cm-searchMatch': { backgroundColor: 'color-mix(in oklab, var(--color-warning) 28%, transparent)' },
    '.cm-foldPlaceholder': {
        backgroundColor: 'var(--color-surface)',
        border: '1px solid var(--color-border-strong)',
        color: 'var(--color-ink-muted)',
    },
    '.cm-tooltip, .cm-panels': {
        backgroundColor: 'var(--color-surface)',
        color: 'var(--color-ink)',
        border: '1px solid var(--color-border-strong)',
    },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
        backgroundColor: 'var(--brand-soft)',
        color: 'var(--color-ink)',
    },
});

// Each syntax colour is a palette colour pulled toward --color-ink, so it keeps
// contrast on light and dark palettes alike. Without this, basicSetup falls back
// to CodeMirror's light-only default style (navy keys on a dark surface).
const mix = (token: string, pct: number) => `color-mix(in oklab, var(${token}) ${pct}%, var(--color-ink))`;
const KEY = mix('--brand', 55);
const KEYWORD = mix('--color-danger', 50);
const STRING = mix('--color-accent', 70);
const LITERAL = mix('--color-warning', 80);

const highlight = HighlightStyle.define([
    { tag: [t.comment, t.lineComment, t.blockComment, t.docComment], color: 'var(--color-ink-faint)', fontStyle: 'italic' },
    { tag: [t.propertyName, t.definition(t.variableName), t.attributeName, t.labelName], color: KEY },
    { tag: [t.heading, t.definition(t.propertyName)], color: KEY, fontWeight: '600' },
    { tag: [t.keyword, t.controlKeyword, t.operatorKeyword, t.modifier, t.self, t.tagName], color: KEYWORD },
    { tag: [t.string, t.special(t.string), t.regexp, t.character, t.url], color: STRING },
    { tag: [t.number, t.bool, t.null, t.atom, t.constant(t.variableName), t.unit], color: LITERAL },
    { tag: [t.typeName, t.className, t.namespace], color: LITERAL },
    { tag: [t.function(t.variableName), t.function(t.propertyName)], color: mix('--brand', 35) },
    { tag: [t.punctuation, t.separator, t.bracket, t.operator, t.meta, t.processingInstruction], color: 'var(--color-ink-muted)' },
    { tag: t.invalid, color: 'var(--color-danger)' },
    { tag: t.strong, fontWeight: '600' },
    { tag: t.emphasis, fontStyle: 'italic' },
    { tag: t.link, textDecoration: 'underline' },
]);

export const editorTheme: Extension = [chrome, syntaxHighlighting(highlight)];
