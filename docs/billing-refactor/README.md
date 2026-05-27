# Billing System Refactor Plan

Planning documents for reconstructing the billing system based on the audit findings.

## Phases Overview

| Phase | Title | Risk | Effort |
|---|---|---|---|
| [0](phase-0-cleanup.md) | Dead code removal & critical bug fixes | Low | Small |
| [1](phase-1-pricing.md) | Price calculation consolidation | Low | Medium |
| [2](phase-2-controllers.md) | Controller deduplication | Medium | Medium |
| [3](phase-3-services.md) | Service layer restructure | High | Large |
| [4](phase-4-performance.md) | Performance: node availability & analytics | Medium | Medium |
| [5](phase-5-data-model.md) | Data model: payment transactions table | High | Large |
| [6](phase-6-frontend.md) | Frontend: checkout flow & UI deduplication | Medium | Large |

## Guiding Principles

- Each phase should be independently deployable without breaking other phases.
- No phase should span more than one "seam" — either backend or frontend, not both.
- Every change to a service must be accompanied by a unit test update or addition.
- Migrations must be additive (no column drops) until the consuming code has shipped.
- Phases 0–2 are safe to do in any order. Phase 3 depends on Phase 2 being complete.
  Phase 5 depends on Phase 3. Phase 6 is independent of all backend phases.

## Audit Reference

Original audit findings are in the parent directory audit. The issues map to phases as:

- **Coupon duplicate path** → Phase 0
- **Dead code / deprecated methods** → Phase 0
- **Double price calculation** → Phase 1
- **`Product::calculatePrice()` in model** → Phase 1
- **Duplicate controller helpers** → Phase 2
- **Dead controller injections** → Phase 2
- **`BillingValidationService` overloaded** → Phase 3
- **`OrderProcessorService` vs `ServerFulfillmentService` overlap** → Phase 3
- **`NodeAvailabilityService` sync Wings pings** → Phase 4
- **Analytics wrong revenue figure** → Phase 4
- **`orders` table catch-all / PayPal columns** → Phase 5
- **`payment_intent_id` overloaded** → Phase 5
- **Frontend dual checkout state** → Phase 6
- **Duplicate payment method selector UI** → Phase 6
