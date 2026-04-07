# Phase 5 Plan — Registration and Loading Cleanup

## Phase Title

Phase 5: Plan Registration/Loading Cleanup and Explicit Module Wiring

## Purpose

Reduce extension wiring ambiguity by making backend and frontend registration paths explicit, discoverable, and consistent.

## What This Phase Will Begin Doing

- Define target registration ownership per layer.
- Identify duplicated or implicit registration points to consolidate.
- Plan transition strategy from mixed implicit/explicit wiring to explicit contracts.
- Align permissions and extension access checks with registration flow.

## Scope

Included:

- Backend route loading strategy review and cleanup plan.
- Frontend extension registry cleanup plan.
- Registration parity strategy across config, backend, and frontend.

Not included:

- Optional override execution model.

## Files / Areas Likely Involved

- `/home/runner/work/M12Labs/M12Labs/routes/api-client.php`
- `/home/runner/work/M12Labs/M12Labs/routes/extensions/client/*.php`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/server/extensions/registry.ts`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/server/extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Middleware/Api/Client/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Requests/Api/Client/Extensions/*`

## Questions to Answer Before Implementation

- Which registration points should remain static and explicit?
- How should backend and frontend registration be validated for parity?
- What is the deprecation strategy for legacy/duplicate registration paths?
- How will extension permission requirements be documented per route surface?

## Likely Work Items

- Draft registration ownership matrix.
- Draft legacy registration deprecation plan.
- Define parity checks between manifest, routes, and frontend registry.
- Document extension access control consistency requirements.

## Risks / Blockers

- High regression risk when touching route loading paths.
- Temporary dual registration can create behavior drift.
- Permission mismatches can expose/deny extension features incorrectly.

## Dependencies on Other Phases

- Requires Phase 2 structural decisions and Phase 3 manifest contract.
- Informs Phase 6 migration and developer docs.

## Expected Output / Deliverables

- Registration cleanup roadmap with sequence steps.
- Explicit wiring rules for backend and frontend.
- Legacy-path deprecation notes.

## Notes for Future Implementation

- Introduce parity assertions before removing old registration paths.
- Keep fallback behavior until all extensions are migrated.
- Validate extension visibility and permissions after each migration step.
- Consider using the TypeScript `satisfies` operator for extension registry entries so the registry gets strong type checking without losing inferred types; this is a good fit for the frontend registry cleanup work in this phase.

## Exit Criteria / Definition of Done

- Registration model is documented and explicit.
- Duplicated/implicit wiring targets are identified with migration steps.
- Implementation-ready cleanup sequence exists with rollback notes.
