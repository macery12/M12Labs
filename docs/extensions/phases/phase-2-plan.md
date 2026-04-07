# Phase 2 Plan — Structure Normalization

## Phase Title

Phase 2: Normalize Extension Folder Structure and Source Layout

## Purpose

Define a predictable extension module layout so maintainers can understand and change extension code without cross-repo scavenger hunts.

## What This Phase Will Begin Doing

- Propose canonical directory boundaries for each extension.
- Map current files into a target layout without changing behavior yet.
- Identify shared infrastructure vs extension-specific code.
- Define naming conventions for IDs, folders, and route segments.

## Scope

Included:

- Target folder contract and naming rules.
- Migration mapping from current paths to future paths.
- Transitional compatibility strategy.

Not included:

- Final registration rewrite.
- Manifest contract rewrite.

## Files / Areas Likely Involved

- `/home/runner/work/M12Labs/M12Labs/app/Http/Controllers/Api/*/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Requests/Api/*/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Middleware/Api/Client/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/routes/extensions/client/*.php`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/server/extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/api/server/extensions/*`

## Questions to Answer Before Implementation

- What is the minimum viable per-extension folder contract?
- Should backend and frontend extension folders mirror each other exactly?
- Which shared utilities should remain centralized vs copied per extension?
- How will transition preserve import stability for existing code?

## Likely Work Items

- Draft canonical extension layout diagram.
- Create migration table: current path → target path.
- Define naming and ownership conventions.
- Mark high-risk moves requiring staged migration.

## Risks / Blockers

- Large rename batches can break imports and route includes.
- Ambiguous shared code boundaries can cause over-abstraction.
- Inconsistent naming can make manifests and registration harder later.

## Dependencies on Other Phases

- Requires Phase 1 inventory and coupling map.

## Expected Output / Deliverables

- Canonical extension module structure specification.
- Migration mapping table and staged move recommendations.
- Shared-vs-local code boundary notes.

## Notes for Future Implementation

- Prefer incremental moves with compatibility bridges.
- Keep route names and API contracts stable during structural migration.
- Gate each move with parity checks against Phase 1 inventory.

## Exit Criteria / Definition of Done

- Target folder structure is documented and unambiguous.
- Existing extensions can be cleanly mapped into the structure.
- Structural migration sequence is ready for implementation phase PRs.
