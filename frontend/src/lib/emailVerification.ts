import { useSession } from '@/state/session';
import { useFlags } from '@/state/flags';
import type { EverestConfiguration } from '@/lib/globals';

export type VerificationArea = 'billing' | 'orders' | 'credentials' | 'tickets';

export interface VerificationGate {
    canView: boolean;
    canInteract: boolean;
}

/**
 * Mirrors EmailVerificationGate::canViewArea / canInteractArea from the rules
 * the everest payload already carries, so a page can explain the gate up front.
 * Without this the tickets page rendered the gate's 403 as "couldn't load,
 * please try again" — advice that can never work. The backend stays
 * authoritative; pages still treat an EMAIL_NOT_VERIFIED error as gated in case
 * the rules changed after this page loaded.
 */
export function useVerificationGate(area: VerificationArea): VerificationGate {
    const verified = useSession(s => s.user?.email_verified);
    const email = useFlags(s => s.everest?.email);
    return verificationGate(verified, email, area);
}

export function verificationGate(
    verified: boolean | undefined,
    email: Pick<EverestConfiguration['email'], 'enabled' | 'verification_rules'> | undefined,
    area: VerificationArea,
): VerificationGate {
    // The gate only enforces while mail delivery is on; otherwise a user could
    // never receive the link. An absent flag means an older payload: don't gate.
    const enforced = verified === false && Boolean(email?.enabled);
    if (!enforced) return { canView: true, canInteract: true };

    const rule = email?.verification_rules?.[area];
    return { canView: rule?.can_view ?? true, canInteract: rule?.can_interact ?? true };
}
