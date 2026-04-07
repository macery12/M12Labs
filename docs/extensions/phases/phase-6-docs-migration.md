# Phase 6 — Docs & Migration Guidance

## Goal

Publish clear migration and authoring documentation for core maintainers and forks.

## Scope

- Write extension author guide.
- Write migration checklist from current layout to target layout.
- Provide troubleshooting for common extension wiring failures.
- Provide examples using existing extensions.

## Required Documentation Outputs

- Extension author quickstart
- Extension manifest reference
- Registration reference (backend + frontend)
- Permissions and access-gating reference
- Migration checklist from legacy extension wiring

## Files / Components Impacted

- `docs/extensions/*`
- Any README pointers from root/module docs (if added later)

## Risks

- Incomplete docs can negate architecture improvements.
- Fork maintainers may rely on old patterns if migration guidance is weak.

## Dependencies

- Phases 1-5 outputs available.

## Done Criteria

- A fork maintainer can add an extension from docs alone.
- Migration from current system to target system is documented step-by-step.
- Legacy behavior expectations and deprecations are clearly called out.
