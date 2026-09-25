import { Component, Suspense, type ComponentType, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Spinner } from '@/components/ui/Spinner';
import { m } from '@/i18n/messages';

interface Props {
    extensionId: string;
    version?: string;
    children: ReactNode;
    /** Slot mounts use null so a failed global contribution does not add chrome. */
    fallback?: ReactNode;
}

interface State {
    error: Error | null;
}

/**
 * Failure isolation for extension pages.
 *
 * Extension code runs in the same bundle and the same React tree as the panel,
 * so without a boundary one throwing package page unmounts the whole route —
 * including the navigation the operator would use to disable it. This catches
 * both render errors and lazy-import failures (a chunk removed by an uninstall
 * that a stale tab still references) and renders an inline failure card.
 *
 * The report carries the extension id and version and nothing else: the error
 * may have been raised while handling user data.
 */
export class ExtensionErrorBoundary extends Component<Props, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('[extension:%s@%s] %s', this.props.extensionId, this.props.version ?? 'unknown', error.message, {
            extension_id: this.props.extensionId,
            extension_version: this.props.version ?? null,
            componentStack: info.componentStack,
        });
    }

    render(): ReactNode {
        if (!this.state.error) return this.props.children;

        if (this.props.fallback !== undefined) return this.props.fallback;

        return (
            <div
                role="alert"
                className="m-4 flex gap-3 rounded-lg border p-4 text-sm"
                style={{
                    background: 'color-mix(in srgb, var(--color-danger) 8%, transparent)',
                    borderColor: 'color-mix(in srgb, var(--color-danger) 30%, transparent)',
                    color: 'var(--color-ink)',
                }}
            >
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" style={{ color: 'var(--color-danger)' }} />
                <div className="space-y-1">
                    <p className="font-medium" style={{ color: 'var(--color-danger)' }}>
                        {m['extensions.boundary.title']({ id: this.props.extensionId })}
                    </p>
                    <p className="text-xs" style={{ color: 'var(--color-ink-muted)' }}>
                        {m['extensions.boundary.body']()}
                    </p>
                </div>
            </div>
        );
    }
}

/** Suspense fallback used while an extension's lazy chunk loads. */
export function ExtensionSuspense({ children }: { children: ReactNode }): ReactNode {
    return (
        <Suspense
            fallback={
                <div className="flex items-center justify-center p-10">
                    <Spinner />
                </div>
            }
        >
            {children}
        </Suspense>
    );
}

/**
 * Wrap a lazily-loaded extension page in its own boundary and suspense.
 * Used by the panel's route registries so every extension surface is isolated
 * without each package having to remember to do it.
 */
export function withExtensionIsolation(
    Element: ComponentType,
    extensionId: string,
    version?: string,
): ComponentType {
    function IsolatedExtensionPage(): ReactNode {
        return (
            <ExtensionErrorBoundary extensionId={extensionId} version={version}>
                <ExtensionSuspense>
                    <Element />
                </ExtensionSuspense>
            </ExtensionErrorBoundary>
        );
    }

    IsolatedExtensionPage.displayName = `ExtensionPage(${extensionId})`;

    return IsolatedExtensionPage;
}
