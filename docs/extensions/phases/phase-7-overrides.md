# Phase 7 — Optional Overrides (Advanced, Non-Default)

## Goal

Document a cautious, optional path for override-based customization without making it the default extension model.

## Status

- **Optional**
- **Advanced**
- **Version-sensitive**
- **Potentially risky for upgrades**

## Scope

- Define override categories (frontend view overrides, backend behavior overrides).
- Define compatibility checks and warning strategy.
- Define fallback behavior when override versions drift.

## Guardrails

- Overrides must never be required to build normal extensions.
- Override mode should emit clear warnings when version contracts do not match.
- Override contracts should include compatibility metadata/version ranges.
- Core extension authoring docs should continue to recommend explicit modules, not overrides.

## Files / Components Impacted

- Future optional override registry and compatibility checker (implementation TBD)
- Documentation for safe use and rollback procedures

## Risks

- High maintenance burden and upgrade fragility.
- Hard-to-debug behavior when overrides shadow core modules.
- Fork divergence from upstream can accelerate if override usage is broad.

## Dependencies

- Stable structure and manifest contracts (Phases 2-3).
- Stable registration model (Phase 5).
- Mature docs/migration layer (Phase 6).

## Done Criteria

- Override design is documented with explicit warnings.
- Compatibility checks are defined before any override execution model is enabled.
- Overrides remain opt-in and clearly separated from standard extension workflow.
