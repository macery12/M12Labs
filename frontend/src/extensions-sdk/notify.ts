import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';

export type NotifyTone = 'success' | 'error' | 'info' | 'warning';

/**
 * Raise a panel toast from an extension page.
 *
 * A plain function rather than a hook: the calls that matter are inside mutation
 * callbacks, where a hook cannot be called. The underlying store is zustand, so
 * reading it imperatively is the supported access — it is the same store the
 * panel's own flash region renders, which is the point. An extension does not
 * get to draw its own notification UI in a corner of the page the panel does not
 * control.
 */
export function notify(tone: NotifyTone, message: string): void {
    useFlashes.getState().push({ type: tone, message });
}

/**
 * The message to show for a failed request, with a fallback the caller supplies
 * in its own locale.
 *
 * Extensions should not parse error envelopes themselves. The panel's error
 * shape (Fractal `errors[].detail`, a flat `message`, a machine-readable `code`)
 * is an internal contract that has already changed once; a package pinning its
 * own copy of that parsing would break the next time it moves.
 */
export function extensionErrorMessage(error: unknown, fallback: string): string {
    return firstError(error) ?? fallback;
}
