# Phase 6 — Frontend: Checkout Flow & UI Deduplication

**Risk:** Medium  
**Effort:** Large (1–2 days)  
**Depends on:** Nothing on the backend (independent)  
**Blocks:** Nothing

---

## Goals

Fix the router-state handoff between `OrderContainer` (configuration step) and the
payment step that makes state management fragile. Deduplicate the payment method selector
UI that exists in two separate components. Add billing cycle selection to the server
renewal flow.

---

## Background

### Current Checkout Architecture

```
/account/billing/order/:id
  → OrderContainer.tsx
      - Node selection
      - Egg selection
      - Billing cycle selection
      - Coupon input
      - Server name input
      - "Continue to Payment" → pushes all state via React Router `state`

  → CheckoutPaymentContainer.tsx (same route, step 2)
      - Reads state from router
      - Payment method selection (duplicated in PaymentContainer)
      - Coupon re-read from router state
      - Fires processUnpaidOrder / Stripe / Mollie / PayPal
```

Problems:
1. If user refreshes on step 2, all router state is lost → crash or empty checkout.
2. Coupon state is re-initialized in step 2 from router state, creating a second source
   of truth. If the user had applied a coupon in step 1 and the coupon expires between
   step 1 and step 2, the price shown in step 2 is stale.
3. `PaymentContainer.tsx` (server renewal page) has a near-identical payment method
   selector UI with the same Stripe/Mollie/PayPal tabs.

### Renewal Flow Missing Cycle Selection

`ServerBillingContainer.tsx` shows a renewal button but uses `billingDays` from the
server's existing state. If a user wants to switch from a 30-day cycle to a 60-day cycle
at renewal, there is no UI for it. The backend already supports this (the fix from the
initial bug report even persists the chosen cycle), but the frontend doesn't expose it.

---

## Tasks

### 6.1 — Replace router-state handoff with URL-persisted order draft

**Problem:** Checkout state (node, egg, billing cycle, coupon, server name) is passed
via React Router `state` which does not survive a refresh.

**Option A (recommended):** Persist the draft to `sessionStorage` on every selection
change and restore it on mount. The router `state` remains as a hint but is not the
single source of truth.

```typescript
// useCheckoutDraft.ts hook
const DRAFT_KEY = 'checkout_draft';

export function useCheckoutDraft(productId: number) {
    const [draft, setDraftState] = useState<CheckoutDraft>(() => {
        const saved = sessionStorage.getItem(`${DRAFT_KEY}_${productId}`);
        return saved ? JSON.parse(saved) : defaultDraft;
    });

    const setDraft = (update: Partial<CheckoutDraft>) => {
        setDraftState(prev => {
            const next = { ...prev, ...update };
            sessionStorage.setItem(`${DRAFT_KEY}_${productId}`, JSON.stringify(next));
            return next;
        });
    };

    const clearDraft = () => {
        sessionStorage.removeItem(`${DRAFT_KEY}_${productId}`);
    };

    return { draft, setDraft, clearDraft };
}
```

Both `OrderContainer` and `CheckoutPaymentContainer` read from the same `useCheckoutDraft`
hook. No router state needed.

**Option B (alternative):** Move both steps into a single component with a local step
state. The product config and payment form are conditional renders within one component.
This eliminates the router handoff entirely but requires a larger refactor of the
`OrderContainer` file.

---

### 6.2 — Extract `PaymentMethodSelector` into a shared component

**Current duplication:**  
`PaymentContainer.tsx` (server renewal) and `CheckoutPaymentContainer.tsx` (new order)
both render a grid of payment method cards (Stripe / Mollie / PayPal) with identical
logic for: checking `billing.processors`, showing unavailability, selected state styling,
and color theming.

**Fix:** Extract to a single reusable component:

```tsx
// resources/scripts/components/billing/PaymentMethodSelector.tsx

interface PaymentMethod {
    id: 'stripe' | 'mollie' | 'paypal';
    available: boolean;
    enabled: boolean;
}

interface Props {
    methods: PaymentMethod[];
    selected: string | undefined;
    onSelect: (method: string) => void;
}

export default function PaymentMethodSelector({ methods, selected, onSelect }: Props) {
    // single implementation of the card grid
}
```

Import `PaymentMethodSelector` in both `PaymentContainer.tsx` and
`CheckoutPaymentContainer.tsx`, removing the duplicated card rendering from each.

---

### 6.3 — Add billing cycle selector to server renewal flow

**File:** `resources/scripts/components/server/billing/ServerBillingContainer.tsx`

**Current state:** `handleFreeRenewal()` passes `billingDays` from `server.billing_days`
(the current cycle) with no UI to change it. The backend correctly handles a different
`billing_days` value on renewal, but the frontend doesn't offer the choice.

**Fix:**

1. Import the `BillingCycleBox` component (already used in `OrderContainer`).
2. Fetch billing cycles for the server's current product on mount:
   ```typescript
   const [billingCycles, setBillingCycles] = useState<BillingCycle[]>([]);
   const [selectedRenewalDays, setSelectedRenewalDays] = useState<number>(billingDays ?? 30);

   useEffect(() => {
       if (billingProductId) {
           getProductBillingCycles(billingProductId).then(setBillingCycles);
       }
   }, [billingProductId]);
   ```
3. Render `BillingCycleBox` above the renewal button when multiple cycles are available.
4. Pass `selectedRenewalDays` to `renewFreeServer()` and `PaymentContainer` instead of
   the hardcoded `billingDays` from server state.

**Note:** Only show the cycle selector when there are >1 available cycles. If the product
has only one cycle configured, no UI change is visible.

---

### 6.4 — Fix stale coupon price display in step-2 handoff

**Current:** Coupon `total` is calculated in `OrderContainer` and passed as router state.
When the user reaches `CheckoutPaymentContainer`, the displayed price may not reflect the
actual charge if the coupon expired or the node selection changed.

**Fix:** `CheckoutPaymentContainer` should re-validate the coupon on mount using the
`/api/client/billing/coupons/validate` endpoint with the current product, billing days,
and node ID. Show a loading state during re-validation. If the coupon is no longer valid,
show a warning and fall back to the non-coupon price.

This is a UX correctness fix and is separate from the draft persistence (6.1), but both
should land together.

---

### 6.5 — Add `billing_days` display to existing server renewal UI

**Minor but visible:** `ServerBillingContainer` shows "Every X days" in the Package
Details card, but uses `actualBillingDays` from server state. Verify this is the stored
`billing_days` column (post Phase 0 fix), not a derived value. After the Phase 0 billing
cycle persistence fix, `server.billing_days` is now authoritative — no frontend change
needed if this is already wired correctly.

**Check:** `ServerContext` state shape for `billing_days`. If it's included in the server
transformer output, no change needed. If it's missing, add it to the transformer.

---

## Acceptance Criteria

- [ ] Refreshing on checkout step 2 restores checkout state (no crash, no empty form).
- [ ] Single `PaymentMethodSelector` component used in both renewal and new-order flows.
- [ ] Server renewal page shows billing cycle selector when >1 cycles are available.
- [ ] Selected renewal cycle is sent to the backend and persisted (billing_days column updated).
- [ ] Coupon is re-validated on entry to the payment step.
- [ ] All checkout flows (free, Stripe, Mollie, PayPal) tested end-to-end manually.
- [ ] No new TypeScript errors.
