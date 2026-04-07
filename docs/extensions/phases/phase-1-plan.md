# Phase 1 Plan — Audit and Inventory

## Phase Title

Phase 1: Audit and Inventory of the Current Extension System

## Purpose

Establish a complete factual baseline of how extensions currently work across backend, frontend, config, DB, permissions, and build flow so later refactor phases are low-risk and reversible.

## What This Phase Will Begin Doing

- Enumerate all extension entry points and registration paths.
- Identify where extension metadata is defined and where runtime state is stored.
- Trace one extension end-to-end from config to route to UI rendering.
- Document duplication, implicit behavior, and areas that are hard for fork maintainers.

## Scope

Included:

- Structure and ownership mapping.
- Backend extension route loading and middleware gates.
- Frontend extension registry and visibility conditions.
- Manifest/config/state relationships.
- Existing extension feature examples and special handling.

Not included:

- Renaming directories.
- Changing manifests.
- Implementing new registration flow.

## Files / Areas Likely Involved

- `/home/runner/work/M12Labs/M12Labs/config/modules/extensions.php`
- `/home/runner/work/M12Labs/M12Labs/routes/api-client.php`
- `/home/runner/work/M12Labs/M12Labs/routes/extensions/client/*.php`
- `/home/runner/work/M12Labs/M12Labs/app/Models/ExtensionConfig.php`
- `/home/runner/work/M12Labs/M12Labs/database/migrations/2026_02_03_000000_create_extension_configs_table.php`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Controllers/Api/Client/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Controllers/Api/Application/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Middleware/Api/Client/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/server/extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/admin/modules/extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/api/server/extensions/*`

## Questions to Answer Before Implementation

- What is the exact source of truth for extension identity and metadata?
- Which extension checks are route-level vs middleware-level vs controller-level?
- Which frontend views depend on server-transformed extension flags?
- Which wiring points are duplicated and can desync?
- Are there current override-like patterns already in use implicitly?

## Likely Work Items

- Build an extension system inventory matrix.
- Build a backend and frontend registration flow map.
- Record permission and gating model per endpoint group.
- Create a risk map of high-coupling locations.

## Risks / Blockers

- Hidden coupling between extension flags and UI rendering conditions.
- Incomplete audit causing migration regressions later.
- Misidentifying derived vs authoritative data sources.

## Dependencies on Other Phases

- None; this phase is foundational.

## Expected Output / Deliverables

- Current-state architecture map.
- Extension inventory list (IDs, routes, UI surfaces, permission gates).
- Refactor risk register for later phases.
- Decision log clarifying current constraints (explicit build-time inclusion, no runtime plugin assumption).

## Notes for Future Implementation

- Preserve behavior parity while restructuring.
- Use audit output as acceptance checklist for every migration PR.
- Keep old and new wiring compare-able until parity is proven.

## Exit Criteria / Definition of Done

- Every active extension can be traced end-to-end in docs.
- Current registration paths are documented with no unknowns.
- Critical duplication points are explicitly listed.
- Phase 2 has enough concrete input to define canonical structure.
