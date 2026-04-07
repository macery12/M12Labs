# Phase 3 Plan — Manifest Standardization

## Phase Title

Phase 3: Define and Standardize Extension Manifest Format

## Purpose

Create a stable metadata contract that reduces drift between config, DB state, backend routes, and frontend display.

## What This Phase Will Begin Doing

- Define required and optional manifest fields.
- Clarify which values are static metadata vs runtime state.
- Plan schema validation and compatibility handling.
- Align manifest semantics with current extension eligibility and settings.

## Scope

Included:

- Manifest schema and semantics documentation.
- Validation strategy and failure behavior planning.
- Compatibility mapping from current config format.

Not included:

- Full registration system rewrite.
- Runtime plugin loading.

## Files / Areas Likely Involved

- `/home/runner/work/M12Labs/M12Labs/config/modules/extensions.php`
- `/home/runner/work/M12Labs/M12Labs/app/Models/ExtensionConfig.php`
- `/home/runner/work/M12Labs/M12Labs/app/Http/Controllers/Api/Application/Extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/admin/modules/extensions/*`
- `/home/runner/work/M12Labs/M12Labs/resources/scripts/components/server/extensions/*`

## Questions to Answer Before Implementation

- Which manifest fields are mandatory to render admin and client surfaces?
- How should versioning and compatibility metadata be represented?
- How should eligibility defaults (nests/eggs) be encoded?
- How will legacy metadata remain readable during migration?

## Likely Work Items

- Draft manifest field catalog.
- Define validation/error reporting behavior.
- Build a legacy-to-standardized field mapping.
- Define manifest ownership and update process.

## Risks / Blockers

- Breaking extension cards or routes due to schema drift.
- Tight validation causing false negatives for existing forks.
- Confusion between DB runtime settings and static manifest values.

## Dependencies on Other Phases

- Requires Phase 1 findings and Phase 2 structural contract.

## Expected Output / Deliverables

- Manifest specification document.
- Compatibility and migration notes for legacy metadata.
- Validation planning notes and rollout strategy.

## Notes for Future Implementation

- Introduce schema changes with compatibility windows.
- Prefer additive fields first, then deprecate legacy keys.
- Track manifest read-path parity before removing legacy readers.

## Exit Criteria / Definition of Done

- Manifest contract is agreed and documented.
- Legacy metadata can be translated without data loss.
- Phase 4 and 5 can reference manifest as a reliable source of truth.
