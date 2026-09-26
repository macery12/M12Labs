import type { PowerState, ServerState } from '@/api/adminServers';

type Tone = 'accent' | 'warning' | 'danger' | 'muted';

// Single source of truth for how a server's lifecycle state is presented:
// a label, a status-color CSS var (for the web-view dots), and a Badge tone.
// "Active" only means "not suspended or mid-operation"; it says nothing about
// whether the server is running, which is POWER_STATE's job.
export const SERVER_STATE: Record<ServerState, { label: string; color: string; tone: Tone }> = {
    active: { label: 'Active', color: 'var(--color-accent)', tone: 'accent' },
    installing: { label: 'Installing', color: 'var(--color-warning)', tone: 'warning' },
    restoring: { label: 'Restoring', color: 'var(--color-warning)', tone: 'warning' },
    transferring: { label: 'Transferring', color: 'var(--color-warning)', tone: 'warning' },
    suspended: { label: 'Suspended', color: 'var(--color-danger)', tone: 'danger' },
    install_failed: { label: 'Install failed', color: 'var(--color-danger)', tone: 'danger' },
};

/** Live power state, plus the two answers the page can have before or without one. */
export type PowerView = PowerState | 'unknown' | 'checking';

export const POWER_STATE: Record<PowerView, { color: string; tone: Tone }> = {
    running: { color: 'var(--color-accent)', tone: 'accent' },
    starting: { color: 'var(--color-warning)', tone: 'warning' },
    stopping: { color: 'var(--color-warning)', tone: 'warning' },
    offline: { color: 'var(--color-ink-faint)', tone: 'muted' },
    unknown: { color: 'var(--color-ink-faint)', tone: 'muted' },
    checking: { color: 'var(--color-border-strong)', tone: 'muted' },
};
