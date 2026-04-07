# Phase 2 — Structure Normalization

## Goal

Define and adopt a predictable folder structure so each extension is understandable as a feature module.

## Scope

- Establish canonical extension folder layout.
- Define where backend handlers, requests, services, and frontend views should live.
- Keep compatibility shims/adapters so existing extensions continue working during migration.

## Proposed Structural Direction

- One extension directory per extension (clear ownership).
- Consistent naming conventions between extension ID, route segment, and folder names.
- Stable boundaries between shared extension infrastructure and extension-specific logic.

## Files / Components Impacted

- Extension route files under `routes/extensions/client`
- Extension controllers/requests/services under `app/Http/.../Extensions` and `app/Services/Extensions`
- Extension frontend components and API files under `resources/scripts/components/server/extensions` and `resources/scripts/api/server/extensions`

## Risks

- Renames can break imports/routes if done without incremental compatibility.
- Over-consolidation may create very large shared abstractions too early.

## Dependencies

- Phase 1 inventory complete.

## Done Criteria

- Target folder contract exists and is documented.
- Existing extensions can be mapped to target structure with no ambiguity.
- Transition plan preserves runtime behavior while moving files incrementally.
