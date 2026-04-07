# Phase 1 — Audit & Inventory

## Goal

Create a complete, implementation-ready inventory of extension system behavior and wiring points.

## Scope

- Catalog extension metadata sources.
- Catalog backend route loading and middleware flow.
- Catalog frontend registration, routes, and API layer touchpoints.
- Catalog permissions and extension-specific authorization patterns.
- Map existing extension implementations and unique behaviors.

## Files / Components Impacted

- `config/modules/extensions.php`
- `routes/api-client.php`
- `routes/extensions/client/*.php`
- `app/Http/Controllers/Api/Client/Extensions/*`
- `app/Http/Controllers/Api/Application/Extensions/*`
- `app/Http/Middleware/Api/Client/Extensions/*`
- `app/Http/Requests/Api/Client/Extensions/*`
- `resources/scripts/components/server/extensions/*`
- `resources/scripts/components/admin/modules/extensions/*`
- `resources/scripts/api/server/extensions/*`
- `resources/scripts/api/routes/admin/extensions/*`

## Risks

- Missing hidden coupling between server flags, permissions, and extension discovery.
- Incomplete inventory can cause migration regressions in later phases.

## Dependencies

- None. This phase is foundational.

## Done Criteria

- Full extension system map is documented.
- All known registration and gating points are listed.
- All existing extension IDs and feature surfaces are inventoried.
- Pain points and duplication areas are explicitly identified.
