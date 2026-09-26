import { describe, expect, it } from 'vitest';
import { verificationGate } from './emailVerification';

const rules = {
    tickets: { can_view: false, can_interact: false },
    billing: { can_view: true, can_interact: false },
};

describe('verificationGate', () => {
    it('applies the area rule to an unverified user while delivery is on', () => {
        expect(verificationGate(false, { enabled: true, verification_rules: rules }, 'tickets')).toEqual({
            canView: false,
            canInteract: false,
        });
        expect(verificationGate(false, { enabled: true, verification_rules: rules }, 'billing')).toEqual({
            canView: true,
            canInteract: false,
        });
    });

    it('never gates a verified user', () => {
        expect(verificationGate(true, { enabled: true, verification_rules: rules }, 'tickets')).toEqual({
            canView: true,
            canInteract: true,
        });
    });

    // EmailVerificationGate::shouldEnforce skips the gate when mail is off, since
    // the user could never receive the link.
    it('does not gate while email delivery is off', () => {
        expect(verificationGate(false, { enabled: false, verification_rules: rules }, 'tickets').canView).toBe(true);
    });

    it('allows an area with no rule, like canViewArea', () => {
        expect(verificationGate(false, { enabled: true, verification_rules: {} }, 'orders')).toEqual({
            canView: true,
            canInteract: true,
        });
    });
});
