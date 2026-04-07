# Phase 4 — Build & Scaffolding Flow

## Goal

Make extension creation in forks low-ceremony and repeatable.

## Scope

- Add a documented extension scaffold workflow (script/command/template).
- Define minimal required files for a new extension.
- Define validation checks to catch registration mismatches early.

## Developer Workflow Target

1. Generate extension skeleton.
2. Fill manifest metadata.
3. Implement backend endpoints/services.
4. Implement frontend page/container.
5. Register extension through explicit documented path.
6. Run build/validation checks.

## Files / Components Impacted

- Documentation under `docs/extensions/*`
- Optional scaffold script/command location (to be selected in implementation)
- CI/build notes for extension validation steps

## Risks

- Scaffold drift from real architecture if not maintained.
- Too much automation too early can hide architecture concepts from maintainers.

## Dependencies

- Manifest contract (Phase 3).

## Done Criteria

- Extension creation steps are documented and reproducible.
- A maintainer can add a simple extension without searching unrelated files.
- Validation steps exist for common wiring mistakes.
