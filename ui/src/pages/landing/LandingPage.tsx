import { m, td } from '@/i18n';
import { Link } from 'react-router-dom';
import { ArrowRight, Gauge, ShieldCheck, Boxes } from 'lucide-react';
import { useFlags } from '@/state/flags';

const features = [
    { icon: Gauge, key: 'speed' },
    { icon: ShieldCheck, key: 'secure' },
    { icon: Boxes, key: 'mods' },
] as const;

export default function LandingPage() {
    const site = useFlags(s => s.site);
    const name = site?.name ?? 'M12Labs';

    return (
        <div className="bg-aurora min-h-screen">
            <header className="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
                <div className="flex items-center gap-2">
                    <div className="h-8 w-8 rounded-lg bg-[var(--brand)]" />
                    <span className="text-lg font-semibold tracking-tight">{name}</span>
                </div>
                <Link
                    to="/v2/auth/login"
                    className="inline-flex h-10 items-center rounded-xl border border-[var(--color-border-strong)] px-4 text-sm font-medium hover:bg-[var(--color-surface-2)]"
                >
                    {m['landing.signIn']()}
                </Link>
            </header>

            <main className="mx-auto max-w-6xl px-6">
                <section className="py-20 text-center sm:py-28">
                    <span className="inline-flex items-center rounded-full border border-[var(--color-border-strong)] bg-[var(--color-surface)]/60 px-3 py-1 text-xs text-[var(--color-ink-muted)]">
                        {m['landing.badge']()}
                    </span>
                    <h1 className="mx-auto mt-6 max-w-3xl text-balance text-5xl font-bold leading-tight tracking-tight sm:text-6xl">
                        {m['landing.heroPre']()}{' '}
                        <span className="text-[var(--brand)]">{m['landing.heroEmphasis']()}</span>.
                    </h1>
                    <p className="mx-auto mt-6 max-w-xl text-pretty text-lg text-[var(--color-ink-muted)]">
                        {m['landing.subtitle']({ name })}
                    </p>
                    <div className="mt-10 flex items-center justify-center gap-3">
                        <Link
                            to="/v2/auth/login"
                            className="group inline-flex h-12 items-center gap-2 rounded-xl bg-[var(--brand)] px-7 text-sm font-semibold text-[var(--color-brand-ink)] shadow-lg shadow-[var(--brand)]/25 hover:bg-[var(--brand-hover)]"
                        >
                            {m['landing.getStarted']()}
                            <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                        </Link>
                    </div>
                </section>

                <section className="grid gap-4 pb-24 sm:grid-cols-3">
                    {features.map(({ icon: Icon, key }) => (
                        <div
                            key={key}
                            className="rounded-2xl border border-[var(--color-border-strong)] bg-[var(--color-surface)]/70 p-6 backdrop-blur"
                        >
                            <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--color-surface-2)]">
                                <Icon className="h-5 w-5 text-[var(--brand)]" />
                            </div>
                            <h3 className="text-base font-semibold">{td(`landing.features.${key}.title`)}</h3>
                            <p className="mt-1.5 text-sm text-[var(--color-ink-muted)]">{td(`landing.features.${key}.body`)}</p>
                        </div>
                    ))}
                </section>
            </main>

            <footer className="border-t border-[var(--color-border)] py-8 text-center text-xs text-[var(--color-ink-faint)]">
                {m['landing.rights']({ year: new Date().getFullYear(), name })}
            </footer>
        </div>
    );
}
