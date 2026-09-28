import { m } from '@/i18n/messages';
import { useQuery } from '@tanstack/react-query';
import { CheckCircle2, Hammer, XCircle } from 'lucide-react';
import { getBuildToolchain, type ToolchainCheck } from '@/api/extensions';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/cn';

// Every install, update and uninstall rebuilds the interface on this server,
// which needs Node, the pinned pnpm major and some disk. The first install on
// a host without them used to fail only once the build had started; this says
// so up front.

function Check({ ok, label }: { ok: boolean; label: string }) {
    const Icon = ok ? CheckCircle2 : XCircle;
    return (
        <span className={cn('inline-flex items-center gap-1.5 font-mono text-xs', ok ? 'text-[var(--color-ink-muted)]' : 'text-[var(--color-danger)]')}>
            <Icon className={cn('h-3.5 w-3.5 shrink-0', ok ? 'text-[var(--color-accent)]' : 'text-[var(--color-danger)]')} />
            {label}
        </span>
    );
}

function toolLabel(name: string, check: ToolchainCheck): string {
    if (!check.found) return m['extensions.toolchain.missing']({ tool: name });
    const version = `${name} ${check.version ?? ''}`.trim();
    return check.ok || !check.required ? version : `${version} (${m['extensions.toolchain.needs']({ required: check.required })})`;
}

export function BuildToolchainRow() {
    const { data } = useQuery({
        queryKey: ['admin', 'extensions', 'toolchain'],
        queryFn: getBuildToolchain,
        staleTime: 60_000,
    });

    if (!data) return null;

    const disk = data.disk.freeBytes === null
        ? m['extensions.toolchain.diskUnknown']()
        : data.disk.ok
            ? m['extensions.toolchain.diskFree']({ size: formatBytes(data.disk.freeBytes) })
            : `${m['extensions.toolchain.diskFree']({ size: formatBytes(data.disk.freeBytes) })} (${m['extensions.toolchain.needs']({ required: formatBytes(data.disk.requiredBytes) })})`;

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-md border px-4 py-3 sm:flex-row sm:items-center sm:gap-4',
                data.ready
                    ? 'border-[var(--color-border-strong)] bg-[var(--color-surface)]/70'
                    : 'border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10',
            )}
        >
            <span className="inline-flex shrink-0 items-center gap-2 text-sm font-medium text-[var(--color-ink)]">
                <Hammer className="h-4 w-4 text-[var(--color-ink-muted)]" />
                {m['extensions.toolchain.title']()}
            </span>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <Check ok={data.node.ok} label={toolLabel('Node', data.node)} />
                <Check ok={data.pnpm.ok} label={toolLabel('pnpm', data.pnpm)} />
                <Check ok={data.disk.ok} label={disk} />
            </div>
            {!data.ready && (
                <p className="text-xs text-[var(--color-danger)] sm:ml-auto sm:max-w-sm">{m['extensions.toolchain.notReady']()}</p>
            )}
        </div>
    );
}
