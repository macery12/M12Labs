import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Check, Copy } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface MarkdownProps {
    content: string;
    className?: string;
    copyLabel?: string;
    copiedLabel?: string;
}

function extractText(node: ReactNode): string {
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(extractText).join('');
    if (node && typeof node === 'object' && 'props' in node) {
        return extractText((node as { props: { children?: ReactNode } }).props.children);
    }

    return '';
}

function CodeBlock({ children, copyLabel, copiedLabel }: { children: ReactNode; copyLabel: string; copiedLabel: string }) {
    const [copied, setCopied] = useState(false);
    const resetTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const text = extractText(children).replace(/\n$/, '');

    useEffect(
        () => () => {
            if (resetTimer.current) clearTimeout(resetTimer.current);
        },
        [],
    );

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            if (resetTimer.current) clearTimeout(resetTimer.current);
            resetTimer.current = setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access can be refused by the browser or permission
            // policy. Leaving the button unchanged is more honest than showing
            // a success state for text that was not copied.
        }
    };

    const label = copied ? copiedLabel : copyLabel;

    return (
        <div className="group/code relative my-2 overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-canvas)]">
            <button
                type="button"
                onClick={() => void copy()}
                title={label}
                aria-label={label}
                className="absolute right-1.5 top-1.5 rounded-md p-1.5 text-[var(--color-ink-faint)] opacity-0 transition-opacity hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] focus:opacity-100 focus:outline-none focus:ring-1 focus:ring-[var(--color-focus-ring)] group-hover/code:opacity-100"
            >
                {copied ? <Check className="h-3.5 w-3.5 text-[var(--color-accent)]" /> : <Copy className="h-3.5 w-3.5" />}
            </button>
            <pre className="overflow-x-auto p-3 pr-10 font-mono text-xs leading-relaxed text-[var(--color-ink)]">
                {children}
            </pre>
        </div>
    );
}

const INLINE_DEPTH_LIMIT = 12;
const BLOCKQUOTE_DEPTH_LIMIT = 8;
const INLINE_MARKERS = ['**', '__', '~~', '*', '_'] as const;
type InlineMarker = (typeof INLINE_MARKERS)[number];

function safeHref(value: string): string | null {
    const href = value.trim();
    if (
        href === '' ||
        [...href].some(character => {
            const code = character.charCodeAt(0);
            return code <= 31 || code === 127;
        })
    ) return null;
    if (/^(?:https?:|mailto:)/i.test(href)) return href;
    if (/^(?:[/#?]|\.\.?\/)/.test(href)) return href;

    // A colon in an otherwise relative-looking value is an unknown scheme.
    // Refusing it also closes mixed-case and whitespace variants of script
    // URLs without attempting to maintain a denylist.
    if (href.includes(':')) return null;

    return href;
}

function formattedNode(marker: InlineMarker, children: ReactNode[], key: string): ReactNode {
    if (marker === '**' || marker === '__') return <strong key={key}>{children}</strong>;
    if (marker === '~~') return <del key={key}>{children}</del>;

    return <em key={key}>{children}</em>;
}

function inlineNodes(text: string, keyPrefix: string, depth = 0): ReactNode[] {
    if (depth >= INLINE_DEPTH_LIMIT) return [text];

    const nodes: ReactNode[] = [];
    let plain = '';
    let index = 0;
    let nodeIndex = 0;
    const flush = () => {
        if (plain !== '') nodes.push(plain);
        plain = '';
    };
    const key = () => `${keyPrefix}-${nodeIndex++}`;

    while (index < text.length) {
        if (text[index] === '\\' && index + 1 < text.length) {
            plain += text[index + 1];
            index += 2;
            continue;
        }

        const image = text.slice(index).match(/^!\[([^\]]*)\]\(([^)]+)\)/);
        if (image) {
            flush();
            nodes.push(<span key={key()} className="italic text-[var(--color-ink-muted)]">{image[1]}</span>);
            index += image[0].length;
            continue;
        }

        const link = text.slice(index).match(/^\[([^\]]+)\]\(([^)]+)\)/);
        if (link) {
            flush();
            const href = safeHref(link[2] ?? '');
            const label = inlineNodes(link[1] ?? '', `${keyPrefix}-link`, depth + 1);
            nodes.push(
                href ? (
                    <a
                        key={key()}
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[var(--brand)] underline decoration-[var(--brand)]/40 underline-offset-2 hover:decoration-[var(--brand)]"
                    >
                        {label}
                    </a>
                ) : (
                    <span key={key()}>{label}</span>
                ),
            );
            index += link[0].length;
            continue;
        }

        if (text[index] === '`') {
            const end = text.indexOf('`', index + 1);
            if (end !== -1) {
                flush();
                nodes.push(
                    <code key={key()} className="rounded bg-[var(--color-surface-2)] px-1 py-0.5 font-mono text-[0.85em] text-[var(--color-ink)]">
                        {text.slice(index + 1, end)}
                    </code>,
                );
                index = end + 1;
                continue;
            }
        }

        const marker = INLINE_MARKERS.find(candidate => text.startsWith(candidate, index));
        if (marker) {
            const end = text.indexOf(marker, index + marker.length);
            if (end > index + marker.length) {
                flush();
                const elementKey = key();
                nodes.push(
                    formattedNode(
                        marker,
                        inlineNodes(text.slice(index + marker.length, end), elementKey, depth + 1),
                        elementKey,
                    ),
                );
                index = end + marker.length;
                continue;
            }
        }

        plain += text[index];
        index++;
    }

    flush();
    return nodes;
}

function splitTableRow(line: string): string[] {
    const source = line.trim().replace(/^\|/, '').replace(/\|$/, '');
    const cells: string[] = [];
    let cell = '';
    let escaped = false;
    let inCode = false;

    for (const character of source) {
        if (escaped) {
            cell += character;
            escaped = false;
        } else if (character === '\\') {
            escaped = true;
        } else if (character === '`') {
            inCode = !inCode;
            cell += character;
        } else if (character === '|' && !inCode) {
            cells.push(cell.trim());
            cell = '';
        } else {
            cell += character;
        }
    }

    if (escaped) cell += '\\';
    cells.push(cell.trim());
    return cells;
}

function tableAlignments(line: string): Array<'left' | 'center' | 'right'> | null {
    const cells = splitTableRow(line);
    if (cells.length === 0 || cells.some(cell => !/^:?-{3,}:?$/.test(cell))) return null;

    return cells.map(cell =>
        cell.startsWith(':') && cell.endsWith(':') ? 'center' : cell.endsWith(':') ? 'right' : 'left',
    );
}

function listItem(line: string): { ordered: boolean; start: number; content: string } | null {
    const unordered = line.match(/^\s{0,3}[-+*]\s+(.+)$/);
    if (unordered) return { ordered: false, start: 1, content: unordered[1] ?? '' };

    const ordered = line.match(/^\s{0,3}(\d+)[.)]\s+(.+)$/);
    if (!ordered) return null;

    return { ordered: true, start: Number(ordered[1]), content: ordered[2] ?? '' };
}

function isBlockStart(lines: string[], index: number): boolean {
    const line = lines[index] ?? '';
    if (line.trim() === '') return true;
    if (/^\s{0,3}(?:`{3,}|~{3,})/.test(line)) return true;
    if (/^\s{0,3}#{1,6}\s+/.test(line)) return true;
    if (/^\s{0,3}>/.test(line)) return true;
    if (/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) return true;
    if (listItem(line)) return true;

    return line.includes('|') && index + 1 < lines.length && tableAlignments(lines[index + 1] ?? '') !== null;
}

function MarkdownBlocks({
    content,
    copyLabel,
    copiedLabel,
    depth = 0,
}: {
    content: string;
    copyLabel: string;
    copiedLabel: string;
    depth?: number;
}) {
    const lines = content.replace(/\r\n?/g, '\n').split('\n');
    const blocks: ReactNode[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index] ?? '';
        if (line.trim() === '') {
            index++;
            continue;
        }

        const fence = line.match(/^\s{0,3}(`{3,}|~{3,})\s*([^\s`]*)\s*$/);
        if (fence) {
            const marker = fence[1] ?? '```';
            const language = fence[2] ?? '';
            const code: string[] = [];
            index++;
            while (index < lines.length) {
                const candidate = lines[index] ?? '';
                const closing = candidate.trim();
                if (
                    closing.length >= marker.length &&
                    closing.split('').every(character => character === marker[0])
                ) {
                    index++;
                    break;
                }
                code.push(candidate);
                index++;
            }
            blocks.push(
                <CodeBlock key={`code-${index}`} copyLabel={copyLabel} copiedLabel={copiedLabel}>
                    <code className={language ? `language-${language}` : undefined}>{code.join('\n')}</code>
                </CodeBlock>,
            );
            continue;
        }

        const heading = line.match(/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/);
        if (heading) {
            const level = heading[1]?.length ?? 1;
            const children = inlineNodes(heading[2] ?? '', `heading-${index}`);
            const headingClass = level === 1
                ? 'mb-1.5 mt-3 text-base font-semibold first:mt-0'
                : 'mb-1 mt-3 text-sm font-semibold first:mt-0';
            blocks.push(level === 1
                ? <h3 key={`heading-${index}`} className={headingClass}>{children}</h3>
                : level === 2
                    ? <h4 key={`heading-${index}`} className={headingClass}>{children}</h4>
                    : <h5 key={`heading-${index}`} className={headingClass}>{children}</h5>);
            index++;
            continue;
        }

        if (/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            blocks.push(<hr key={`rule-${index}`} className="my-3 border-[var(--color-border)]" />);
            index++;
            continue;
        }

        if (/^\s{0,3}>/.test(line)) {
            const quoted: string[] = [];
            while (index < lines.length && /^\s{0,3}>/.test(lines[index] ?? '')) {
                quoted.push((lines[index] ?? '').replace(/^\s{0,3}>\s?/, ''));
                index++;
            }
            blocks.push(
                <blockquote key={`quote-${index}`} className="my-2 border-l-2 border-[var(--color-border-strong)] pl-3 text-[var(--color-ink-muted)]">
                    {depth < BLOCKQUOTE_DEPTH_LIMIT
                        ? <MarkdownBlocks content={quoted.join('\n')} copyLabel={copyLabel} copiedLabel={copiedLabel} depth={depth + 1} />
                        : inlineNodes(quoted.join(' '), `quote-${index}`)}
                </blockquote>,
            );
            continue;
        }

        const alignment = index + 1 < lines.length ? tableAlignments(lines[index + 1] ?? '') : null;
        if (line.includes('|') && alignment) {
            const headers = splitTableRow(line);
            const rows: string[][] = [];
            index += 2;
            while (index < lines.length && (lines[index] ?? '').includes('|') && (lines[index] ?? '').trim() !== '') {
                rows.push(splitTableRow(lines[index] ?? ''));
                index++;
            }
            blocks.push(
                <div key={`table-${index}`} className="my-2 overflow-x-auto">
                    <table className="w-full border-collapse text-xs">
                        <thead>
                            <tr>
                                {headers.map((header, cellIndex) => (
                                    <th
                                        key={`header-${cellIndex}`}
                                        style={{ textAlign: alignment[cellIndex] ?? 'left' }}
                                        className="border border-[var(--color-border)] bg-[var(--color-surface-2)] px-2 py-1 font-semibold"
                                    >
                                        {inlineNodes(header, `header-${cellIndex}`)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, rowIndex) => (
                                <tr key={`row-${rowIndex}`}>
                                    {headers.map((_, cellIndex) => (
                                        <td
                                            key={`cell-${cellIndex}`}
                                            style={{ textAlign: alignment[cellIndex] ?? 'left' }}
                                            className="border border-[var(--color-border)] px-2 py-1 align-top"
                                        >
                                            {inlineNodes(row[cellIndex] ?? '', `cell-${rowIndex}-${cellIndex}`)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>,
            );
            continue;
        }

        const firstItem = listItem(line);
        if (firstItem) {
            const items: string[] = [];
            const ordered = firstItem.ordered;
            const start = firstItem.start;
            while (index < lines.length) {
                const item = listItem(lines[index] ?? '');
                if (!item || item.ordered !== ordered) break;
                items.push(item.content);
                index++;
            }
            const children = items.map((item, itemIndex) => {
                const task = item.match(/^\[([ xX])\]\s+(.+)$/);
                return (
                    <li key={`item-${itemIndex}`} className="marker:text-[var(--color-ink-faint)]">
                        {task && (
                            <input
                                type="checkbox"
                                checked={(task[1] ?? '').toLowerCase() === 'x'}
                                readOnly
                                tabIndex={-1}
                                aria-hidden="true"
                                className="mr-1.5 align-middle"
                            />
                        )}
                        {inlineNodes(task?.[2] ?? item, `item-${itemIndex}`)}
                    </li>
                );
            });
            blocks.push(ordered
                ? <ol key={`list-${index}`} start={start} className="my-1.5 list-decimal space-y-1 pl-5">{children}</ol>
                : <ul key={`list-${index}`} className="my-1.5 list-disc space-y-1 pl-5">{children}</ul>);
            continue;
        }

        const paragraph = [line.trim()];
        index++;
        while (index < lines.length && !isBlockStart(lines, index)) {
            paragraph.push((lines[index] ?? '').trim());
            index++;
        }
        blocks.push(
            <p key={`paragraph-${index}`} className="my-1.5 first:mt-0 last:mb-0">
                {inlineNodes(paragraph.join(' '), `paragraph-${index}`)}
            </p>,
        );
    }

    return <>{blocks}</>;
}

/**
 * Safe, themed Markdown for extension-authored UI.
 *
 * This dependency-free renderer covers the chat-facing GFM subset: headings,
 * emphasis, links, quotes, lists and task lists, tables, rules, inline code and
 * fenced code. Raw HTML is text, unknown URL schemes are not linked, and
 * images render as alt text rather than issuing a tracking request.
 */
export function Markdown({ content, className, copyLabel = 'Copy code', copiedLabel = 'Copied' }: MarkdownProps) {
    return (
        <div className={cn('min-w-0 break-words text-sm leading-relaxed text-[var(--color-ink)]', className)}>
            <MarkdownBlocks content={content} copyLabel={copyLabel} copiedLabel={copiedLabel} />
        </div>
    );
}
