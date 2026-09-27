import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { abs } from '@/lib/base';
import { useQuery } from '@tanstack/react-query';
import { ChevronUp, ChevronDown, Plus, Trash2, ExternalLink, EyeOff } from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Input, Field } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { useFlags } from '@/state/flags';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { getLandingConfig, updateLandingConfig } from '@/api/landing';
import type {
    LandingConfiguration,
    LandingSection,
    LandingSectionId,
    LandingFeatureItem,
    LandingFaqItem,
    LandingTestimonialItem,
} from '@/lib/globals';
import { IconPicker } from '@/components/ui/IconPicker';
import { useWideContent } from '@/components/shell/shellLayout';
import LandingCanvas from '@/pages/landing/LandingCanvas';
import { DEFAULTS as FEATURE_DEFAULTS } from '@/pages/landing/sections/Features';
import { getCatalog, type StorefrontCategory } from '@/api/storefront';
import { collectCopy, duplicateNames, looksLikePlaceholder } from '@/lib/contentChecks';
import { ContentIssues, type ContentIssue } from '@/components/ui/ContentIssues';

const SECTION_META: Record<LandingSectionId, { titleKey: string; descKey: string }> = {
    hero: { titleKey: 'landingAdmin.hero.title', descKey: 'landingAdmin.hero.desc' },
    features: { titleKey: 'ui.labels.featureCards', descKey: 'ui.messages.featureCardsHint' },
    pricing: { titleKey: 'ui.labels.pricing', descKey: 'landingAdmin.pricing.desc' },
    faq: { titleKey: 'landingAdmin.faq.title', descKey: 'landingAdmin.faq.desc' },
    testimonials: { titleKey: 'landingAdmin.testimonials.title', descKey: 'landingAdmin.testimonials.desc' },
    custom: { titleKey: 'ui.labels.customBlock', descKey: 'ui.messages.customBlockHint' },
};

function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            {...props}
            className={cn(
                'min-h-[88px] w-full rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface-2)] px-4 py-3 text-sm text-[var(--color-ink)]',
                'placeholder:text-[var(--color-ink-faint)] transition-colors focus:outline-none focus:ring-1 focus:ring-[var(--color-focus-ring)] focus:border-[var(--color-focus)]',
                props.className,
            )}
        />
    );
}

export default function LandingSection() {
    const push = useFlashes(s => s.push);
    const site = useFlags(s => s.site);
    const name = site?.name ?? 'M12Labs';
    const [config, setConfig] = useState<LandingConfiguration | null>(window.LandingConfiguration ?? null);
    const [saved, setSaved] = useState<LandingConfiguration | null>(window.LandingConfiguration ?? null);
    const [saving, setSaving] = useState(false);
    const [selectedId, setSelectedId] = useState<LandingSectionId>('hero');

    // This editor is a three-column layout — let it use the full width instead of
    // the default centered `max-w-6xl` that squishes the columns.
    useWideContent();

    // Fetch from the API if nothing was injected (defensive — the composer
    // normally injects it on every page load).
    useEffect(() => {
        if (config) return;
        getLandingConfig()
            .then(c => {
                setConfig(c);
                setSaved(c);
            })
            .catch(err => push({ type: 'error', message: firstError(err) ?? m['landingAdmin.loadError']() }));
    }, [config, push]);

    const dirty = useMemo(() => JSON.stringify(config) !== JSON.stringify(saved), [config, saved]);

    // Plans the Pricing section would show, for the duplicate-name check.
    const catalogQ = useQuery({ queryKey: ['storefront', 'catalog'], queryFn: getCatalog, staleTime: 60_000 });
    const issues = useMemo(
        () => (config?.enabled ? contentIssues(config.sections, catalogQ.data ?? [], setSelectedId) : []),
        [config, catalogQ.data],
    );

    if (!config) {
        return (
            <div className="flex items-center justify-center py-20">
                <Spinner className="h-6 w-6" />
            </div>
        );
    }

    const sections = config.sections;
    const selectedIndex = Math.max(0, sections.findIndex(s => s.id === selectedId));
    const selected = sections[selectedIndex];

    const updateSection = (index: number, patch: Partial<LandingSection>) =>
        setConfig(c => (c ? { ...c, sections: c.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)) } : c));

    const updateData = (index: number, data: Record<string, unknown>) => {
        const current = sections[index];
        if (!current) return;
        updateSection(index, { data: { ...current.data, ...data } });
    };

    const move = (index: number, dir: -1 | 1) => {
        const target = index + dir;
        if (target < 0 || target >= sections.length) return;
        setConfig(c => {
            if (!c) return c;
            const next = c.sections.slice();
            const a = next[index];
            const b = next[target];
            if (!a || !b) return c;
            next[index] = b;
            next[target] = a;
            // Renumber order to match the new visual order.
            return { ...c, sections: next.map((s, i) => ({ ...s, order: i })) };
        });
    };

    const handleSave = async () => {
        if (saving || !config) return;
        setSaving(true);
        const normalized: LandingConfiguration = {
            enabled: config.enabled,
            sections: config.sections.map((s, i) => ({ ...s, order: i })),
        };
        try {
            await updateLandingConfig(normalized);
            // Mirror onto the injected global so a re-render (and the public page in
            // this tab) reflect the saved config without a reload.
            window.LandingConfiguration = normalized;
            setSaved(normalized);
            setConfig(normalized);
            push({ type: 'success', message: m['landingAdmin.saved']() });
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['landingAdmin.saveError']() });
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">{m['landingAdmin.title']()}</h1>
                    <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['landingAdmin.subtitle']()}</p>
                </div>
                <div className="flex items-center gap-2">
                    <a
                        href={abs()}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex h-10 items-center gap-2 rounded-lg border border-[var(--color-border-strong)] px-4 text-sm font-medium hover:bg-[var(--color-surface-2)]"
                    >
                        <ExternalLink className="h-4 w-4" />
                        {m['landingAdmin.preview']()}
                    </a>
                    <Button disabled={!dirty || saving} onClick={handleSave}>
                        {saving ? <Spinner className="h-4 w-4" /> : m['common.actions.saveChanges']()}
                    </Button>
                </div>
            </header>

            <ContentIssues issues={issues} />

            <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)]/60 p-5">
                <span className="min-w-0 flex-1">
                    <span className="block text-base font-semibold text-[var(--color-ink)]">{m['landingAdmin.enabled']()}</span>
                    <span className="mt-0.5 block text-sm text-[var(--color-ink-muted)]">{m['landingAdmin.enabledHelp']()}</span>
                </span>
                <Switch checked={config.enabled} onChange={v => setConfig(c => (c ? { ...c, enabled: v } : c))} className="mt-1" />
            </label>

            <div
                className={cn(
                    'grid grid-cols-1 gap-6 xl:grid-cols-[210px_minmax(0,1fr)_minmax(0,440px)]',
                    !config.enabled && 'pointer-events-none opacity-50',
                )}
            >
                {/* Left rail: section list — select, toggle, reorder. */}
                <div>
                    <h2 className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">
                        {m['ui.labels.sections']()}
                    </h2>
                    <ul className="flex flex-col gap-1">
                        {sections.map((section, index) => (
                            <SectionListRow
                                key={section.id}
                                section={section}
                                selected={section.id === selectedId}
                                isFirst={index === 0}
                                isLast={index === sections.length - 1}
                                onSelect={() => setSelectedId(section.id)}
                                onToggle={v => updateSection(index, { enabled: v })}
                                onMove={dir => move(index, dir)}
                            />
                        ))}
                    </ul>
                </div>

                {/* Center: editor for the selected section. */}
                {selected && (
                    <div className="rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)]/60 p-5">
                        <div className="mb-5 flex items-start justify-between gap-3 border-b border-[var(--color-border)] pb-4">
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-[var(--color-ink)]">{td(SECTION_META[selected.id].titleKey)}</h2>
                                <p className="text-sm text-[var(--color-ink-muted)]">{td(SECTION_META[selected.id].descKey)}</p>
                            </div>
                            <Switch checked={selected.enabled} onChange={v => updateSection(selectedIndex, { enabled: v })} className="mt-0.5" />
                        </div>
                        {!selected.enabled && (
                            <p className="mb-4 flex items-center gap-2 rounded-lg bg-[var(--color-surface-2)] px-3 py-2 text-xs text-[var(--color-ink-muted)]">
                                <EyeOff className="h-3.5 w-3.5 shrink-0" />
                                {m['landingAdmin.hiddenNotice']()}
                            </p>
                        )}
                        <SectionFields section={selected} name={name} onData={data => updateData(selectedIndex, data)} />
                    </div>
                )}

                {/* Right: live, scaled preview of the whole page. */}
                <div className="xl:sticky xl:top-6 xl:self-start">
                    <h2 className="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">
                        {m['ui.labels.livePreview']()}
                    </h2>
                    <p className="mb-2 px-1 text-xs text-[var(--color-ink-faint)]">{m['ui.messages.livePreviewHint']()}</p>
                    <div className="max-h-[80vh] overflow-y-auto rounded-lg border border-[var(--color-border-strong)]">
                        <PreviewFrame>
                            <LandingCanvas sections={config.sections} name={name} logo={site?.logo} highlightId={selectedId} />
                        </PreviewFrame>
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * Filler text in enabled sections, and plan names the Pricing section would
 * show twice. Warnings only (D5): the page still saves.
 */
function contentIssues(
    sections: LandingSection[],
    catalog: StorefrontCategory[],
    select: (id: LandingSectionId) => void,
): ContentIssue[] {
    const issues: ContentIssue[] = [];
    for (const s of sections) {
        if (!s.enabled) continue;
        const section = td(SECTION_META[s.id].titleKey);
        collectCopy(s.data).forEach((text, i) => {
            if (looksLikePlaceholder(text)) {
                issues.push({
                    key: `${s.id}-${i}`,
                    message: m['common.contentChecks.placeholder']({ section, text: text.trim() }),
                    onFix: () => select(s.id),
                });
            }
        });
        if (s.id === 'pricing') {
            const ids = s.data.categoryIds ?? [];
            const shown = catalog.filter(c => ids.length === 0 || ids.includes(c.id));
            for (const name of duplicateNames(shown.flatMap(c => c.products.map(p => p.name)))) {
                issues.push({
                    key: `pricing-dup-${name}`,
                    message: m['common.contentChecks.duplicate']({ section, name }),
                    to: '/admin/billing/products',
                });
            }
        }
    }
    return issues;
}

// Renders the supplied children at a fixed design width, scaled down to fit the
// available column so the editor shows a faithful, miniature page. A
// ResizeObserver keeps the scale + height in sync as the column resizes and as
// the content grows/shrinks while the operator types.
const DESIGN_WIDTH = 1280;
function PreviewFrame({ children }: { children: React.ReactNode }) {
    const outer = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(0.3);
    const [height, setHeight] = useState(0);

    useLayoutEffect(() => {
        const o = outer.current;
        const i = inner.current;
        if (!o || !i) return;
        const update = () => {
            const s = o.clientWidth / DESIGN_WIDTH;
            setScale(s);
            setHeight(i.offsetHeight * s);
        };
        update();
        const ro = new ResizeObserver(update);
        ro.observe(o);
        ro.observe(i);
        return () => ro.disconnect();
    }, []);

    return (
        <div ref={outer} className="w-full overflow-hidden" style={{ height }}>
            {/* Static, non-interactive: clicks/links shouldn't navigate away from the editor. */}
            <div
                ref={inner}
                aria-hidden
                className="pointer-events-none select-none"
                style={{ width: DESIGN_WIDTH, transform: `scale(${scale})`, transformOrigin: 'top left' }}
            >
                {children}
            </div>
        </div>
    );
}

function SectionListRow({
    section,
    selected,
    isFirst,
    isLast,
    onSelect,
    onToggle,
    onMove,
}: {
    section: LandingSection;
    selected: boolean;
    isFirst: boolean;
    isLast: boolean;
    onSelect: () => void;
    onToggle: (v: boolean) => void;
    onMove: (dir: -1 | 1) => void;
}) {
    const meta = SECTION_META[section.id];
    return (
        <li
            className={cn(
                'flex items-center gap-1 rounded-lg border px-2 py-1.5',
                selected
                    ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                    : 'border-transparent hover:bg-[var(--color-surface-2)]',
            )}
        >
            <div className="flex flex-col">
                <button
                    type="button"
                    disabled={isFirst}
                    onClick={() => onMove(-1)}
                    className="text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] disabled:opacity-30"
                    aria-label={m['ui.actions.moveUp']()}
                >
                    <ChevronUp className="h-3.5 w-3.5" />
                </button>
                <button
                    type="button"
                    disabled={isLast}
                    onClick={() => onMove(1)}
                    className="text-[var(--color-ink-faint)] hover:text-[var(--color-ink)] disabled:opacity-30"
                    aria-label={m['ui.actions.moveDown']()}
                >
                    <ChevronDown className="h-3.5 w-3.5" />
                </button>
            </div>
            <button type="button" onClick={onSelect} className="min-w-0 flex-1 py-1 text-left">
                <span className={cn('block truncate text-sm font-medium', section.enabled ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-faint)]')}>
                    {td(meta.titleKey)}
                </span>
                {!section.enabled && (
                    <span className="text-[10px] font-medium uppercase tracking-wide text-[var(--color-ink-faint)]">
                        {m['ui.states.hidden']()}
                    </span>
                )}
            </button>
            <Switch checked={section.enabled} onChange={onToggle} />
        </li>
    );
}

function SectionFields({
    section,
    name,
    onData,
}: {
    section: LandingSection;
    name: string;
    onData: (data: Record<string, unknown>) => void;
}) {
    const d = section.data;

    switch (section.id) {
        case 'hero':
            return (
                <div className="grid gap-5">
                    {/* Placeholders are the real default copy, so a blank field shows
                        exactly what the page will say. */}
                    <p className="text-xs text-[var(--color-ink-faint)]">{m['landingAdmin.field.defaultsNote']()}</p>
                    <Field label={m['ui.labels.badge']()} hint={m['landingAdmin.field.badgeHelp']()}>
                        <Input value={d.badge ?? ''} maxLength={120} placeholder={m['landing.eyebrow']()} onChange={e => onData({ badge: e.target.value })} />
                    </Field>
                    <Field label={m['ui.labels.heading']()} hint={m['landingAdmin.field.heroHeadingHelp']()}>
                        <Input value={d.title ?? ''} maxLength={200} placeholder={m['landing.heroTitle']()} onChange={e => onData({ title: e.target.value })} />
                    </Field>
                    <Field label={m['ui.labels.subtitle']()} hint={m['landingAdmin.field.subtitleHelp']()}>
                        <Textarea value={d.subtitle ?? ''} maxLength={600} placeholder={m['landing.subtitle']({ name })} onChange={e => onData({ subtitle: e.target.value })} />
                    </Field>
                    <Field label={m['ui.labels.backgroundImageUrl']()} hint={m['ui.messages.heroBackgroundHint']()}>
                        <Input type="url" value={d.backgroundImage ?? ''} maxLength={500} onChange={e => onData({ backgroundImage: e.target.value })} />
                    </Field>
                    <div className="grid gap-5 md:grid-cols-2">
                        <CtaEditor
                            label={m['landingAdmin.field.primaryCta']()}
                            hint={m['ui.messages.primaryButtonHint']()}
                            cta={d.primaryCta ?? { label: '', href: '' }}
                            labelPlaceholder={m['landing.getStarted']()}
                            hrefPlaceholder={abs('/auth/login')}
                            onChange={cta => onData({ primaryCta: cta })}
                        />
                        <CtaEditor
                            label={m['landingAdmin.field.secondaryCta']()}
                            hint={m['landingAdmin.field.secondaryCtaHelp']()}
                            cta={d.secondaryCta ?? { label: '', href: '' }}
                            hrefPlaceholder={abs('/auth/login')}
                            onChange={cta => onData({ secondaryCta: cta })}
                        />
                    </div>
                </div>
            );

        case 'features': {
            const items = (d.items ?? []) as LandingFeatureItem[];
            return (
                <ItemList
                    items={items}
                    onChange={next => onData({ items: next })}
                    blank={{ icon: 'Gauge', title: '', body: '' }}
                    addLabel={m['ui.actions.addFeature']()}
                    render={(item, update, index) => (
                        <div className="grid gap-3">
                            <Field label={m['ui.labels.icon']()} hint={m['landingAdmin.field.iconHelp']()}>
                                <IconPicker value={item.icon} onChange={icon => update({ icon })} />
                            </Field>
                            <Field label={m['ui.labels.heading']()} hint={m['landingAdmin.field.featureHeadingHelp']()}>
                                <Input
                                    value={item.title}
                                    maxLength={160}
                                    placeholder={FEATURE_DEFAULTS[index] ? td(FEATURE_DEFAULTS[index].titleKey) : undefined}
                                    onChange={e => update({ title: e.target.value })}
                                />
                            </Field>
                            <Field label={m['ui.labels.body']()} hint={m['landingAdmin.field.featureBodyHelp']()}>
                                <Textarea
                                    value={item.body}
                                    maxLength={800}
                                    placeholder={FEATURE_DEFAULTS[index] ? td(FEATURE_DEFAULTS[index].bodyKey) : undefined}
                                    onChange={e => update({ body: e.target.value })}
                                />
                            </Field>
                        </div>
                    )}
                />
            );
        }

        case 'pricing':
            return (
                <div className="grid gap-5">
                    <Field label={m['ui.labels.heading']()} hint={m['landingAdmin.field.pricingHeadingHelp']()}>
                        <Input value={d.heading ?? ''} maxLength={200} placeholder={m['landing.pricing.heading']()} onChange={e => onData({ heading: e.target.value })} />
                    </Field>
                    <p className="text-xs text-[var(--color-ink-faint)]">{m['landingAdmin.pricing.note']()}</p>
                </div>
            );

        case 'faq': {
            const items = (d.items ?? []) as LandingFaqItem[];
            return (
                <ItemList
                    items={items}
                    onChange={next => onData({ items: next })}
                    blank={{ q: '', a: '' }}
                    addLabel={m['landingAdmin.addFaq']()}
                    render={(item, update) => (
                        <div className="grid gap-3">
                            <Field label={m['landingAdmin.field.question']()} hint={m['landingAdmin.field.questionHelp']()}>
                                <Input value={item.q} maxLength={200} onChange={e => update({ q: e.target.value })} />
                            </Field>
                            <Field label={m['landingAdmin.field.answer']()} hint={m['landingAdmin.field.answerHelp']()}>
                                <Textarea value={item.a} maxLength={1200} onChange={e => update({ a: e.target.value })} />
                            </Field>
                        </div>
                    )}
                />
            );
        }

        case 'testimonials': {
            const items = (d.items ?? []) as LandingTestimonialItem[];
            return (
                <ItemList
                    items={items}
                    onChange={next => onData({ items: next })}
                    blank={{ quote: '', author: '', role: '' }}
                    addLabel={m['landingAdmin.addTestimonial']()}
                    render={(item, update) => (
                        <div className="grid gap-3">
                            <Field label={m['landingAdmin.field.quote']()} hint={m['landingAdmin.field.quoteHelp']()}>
                                <Textarea value={item.quote} maxLength={600} onChange={e => update({ quote: e.target.value })} />
                            </Field>
                            <div className="grid gap-3 md:grid-cols-2">
                                <Field label={m['landingAdmin.field.author']()} hint={m['landingAdmin.field.authorHelp']()}>
                                    <Input value={item.author} maxLength={120} onChange={e => update({ author: e.target.value })} />
                                </Field>
                                <Field label={m['landingAdmin.field.role']()} hint={m['landingAdmin.field.roleHelp']()}>
                                    <Input value={item.role} maxLength={120} onChange={e => update({ role: e.target.value })} />
                                </Field>
                            </div>
                        </div>
                    )}
                />
            );
        }

        case 'custom':
            return (
                <div className="grid gap-5">
                    <Field label={m['ui.labels.heading']()} hint={m['ui.messages.blockHeadingHint']()}>
                        <Input value={d.title ?? ''} maxLength={200} onChange={e => onData({ title: e.target.value })} />
                    </Field>
                    <Field label={m['ui.labels.body']()} hint={m['ui.messages.plainTextHint']()}>
                        <Textarea value={d.body ?? ''} maxLength={5000} className="min-h-[160px]" onChange={e => onData({ body: e.target.value })} />
                    </Field>
                </div>
            );

        default:
            return null;
    }
}

function CtaEditor({
    label,
    hint,
    cta,
    labelPlaceholder,
    hrefPlaceholder,
    onChange,
}: {
    label: string;
    hint?: string;
    cta: { label: string; href: string };
    labelPlaceholder?: string;
    hrefPlaceholder?: string;
    onChange: (cta: { label: string; href: string }) => void;
}) {
    return (
        <fieldset className="rounded-lg border border-[var(--color-border)] p-4">
            <legend className="px-1 text-sm font-medium text-[var(--color-ink-muted)]">{label}</legend>
            {hint && <p className="mb-2 text-xs text-[var(--color-ink-faint)]">{hint}</p>}
            <div className="grid gap-3">
                <Input placeholder={labelPlaceholder ?? m['ui.labels.buttonLabel']()} value={cta.label} maxLength={60} onChange={e => onChange({ ...cta, label: e.target.value })} />
                <Input placeholder={hrefPlaceholder ?? m['landingAdmin.field.ctaHref']()} value={cta.href} maxLength={300} onChange={e => onChange({ ...cta, href: e.target.value })} />
            </div>
        </fieldset>
    );
}

// Generic add/remove/edit list for the repeated-item sections.
function ItemList<T extends object>({
    items,
    onChange,
    blank,
    addLabel,
    render,
}: {
    items: T[];
    onChange: (next: T[]) => void;
    blank: T;
    addLabel: string;
    render: (item: T, update: (patch: Partial<T>) => void, index: number) => React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4">
            {items.map((item, i) => (
                <div key={i} className="rounded-lg border border-[var(--color-border)] p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-faint)]">#{i + 1}</span>
                        <button
                            type="button"
                            onClick={() => onChange(items.filter((_, idx) => idx !== i))}
                            className="text-[var(--color-ink-faint)] hover:text-[var(--color-danger)]"
                            aria-label={m['common.actions.remove']()}
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </div>
                    {render(item, patch => onChange(items.map((it, idx) => (idx === i ? { ...it, ...patch } : it))), i)}
                </div>
            ))}
            <Button variant="outline" size="sm" className="self-start" onClick={() => onChange([...items, { ...blank }])}>
                <Plus className="h-4 w-4" />
                {addLabel}
            </Button>
        </div>
    );
}
