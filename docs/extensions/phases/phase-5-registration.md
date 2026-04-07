# Phase 5 — Registration Cleanup

## Goal

Consolidate extension registration so wiring is explicit and easy to reason about.

## Scope

- Reduce duplicated registration across backend and frontend.
- Define one predictable registration path per layer.
- Remove hidden/implicit behavior where practical.

## Cleanup Targets

- Backend route registration clarity (current glob approach and alternatives).
- Frontend extension page registration (`registry.ts`) alignment with manifest/source-of-truth.
- Permission and middleware consistency across all extension endpoints.

## Files / Components Impacted

- `routes/api-client.php`
- `routes/extensions/client/*.php`
- `resources/scripts/components/server/extensions/registry.ts`
- Extension API clients/components and server route integration
- Request/middleware classes used for extension gating

## Risks

- Registration changes are high-risk for regressions.
- Temporary dual-registration period can cause confusion if not documented.

## Dependencies

- Phase 2 structure normalization and Phase 3 manifest standardization.

## Done Criteria

- Registration responsibilities are documented and singular.
- No extension requires undocumented side wiring to appear and function.
- Existing extensions function after cleanup with parity checks.
