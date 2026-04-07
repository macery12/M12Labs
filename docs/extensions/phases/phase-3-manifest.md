# Phase 3 — Manifest Standardization

## Goal

Create a clear extension manifest contract and reduce metadata duplication.

## Scope

- Standardize required and optional manifest fields.
- Define relationship between static manifest and runtime DB settings.
- Add validation for manifest shape and incompatible values.

## Candidate Manifest Fields

- `id`
- `name`
- `description`
- `version`
- `author`
- `icon`
- `route`
- `settingsSchema`
- eligibility defaults (nests/eggs)
- permission expectations (if needed for docs/validation)

## Files / Components Impacted

- `config/modules/extensions.php` (or successor source)
- Admin extension API responses
- Admin extension settings UI rendering
- Any build or validation utility added for manifest checks

## Risks

- Manifest migration can break extension cards or routing if fields drift.
- Tight validation may block existing fork customizations unless migration notes are clear.

## Dependencies

- Phase 1 audit, Phase 2 structure decisions.

## Done Criteria

- Manifest contract documented with field semantics.
- Existing extensions conform (or have documented compatibility shims).
- Admin and client extension metadata reading paths are consistent.
