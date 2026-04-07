# Extensions Refactor Planning Docs

This folder contains the planning roadmap for refactoring the extension system in `macery12/M12Labs`.

## Purpose

The plan is focused on making extensions:

- easier to understand,
- easier to maintain,
- easier to add in forked repositories,
- easier to document,
- easier to evolve over time.

This is **not** a runtime plugin-system plan. It assumes extensions remain explicit, first-party feature modules compiled into the panel frontend.

## Current System (Audit Snapshot)

- Metadata and extension list are declared in `config/modules/extensions.php`.
- Runtime extension state is stored in `extension_configs` (`enabled`, `allowed_nests`, `allowed_eggs`, `settings`).
- Client extension routes are loaded by globbing `routes/extensions/client/*.php` from `routes/api-client.php`.
- Server extension frontend pages are registered in `resources/scripts/components/server/extensions/registry.ts`.
- Admin extension management uses `/api/application/extensions/*`.
- Server extension list/check uses `/api/client/servers/{server}/extensions*`.
- Permission model uses `extension.read` and `extension.manage`, often combined with feature-specific permissions (for example file permissions).

## Target Direction (High-Level)

- One folder per extension (clear ownership boundary).
- One manifest per extension (single source of extension metadata).
- Explicit backend/frontend registration paths (minimize hidden wiring).
- Build-time frontend inclusion (no runtime dynamic client plugins).
- Strong docs/scaffolding for fork maintainers.

## Documents

- [Refactor Plan](./refactor-plan.md)
- [Phase 1 — Audit & Inventory](./phases/phase-1-audit.md)
- [Phase 2 — Structure Normalization](./phases/phase-2-structure.md)
- [Phase 3 — Manifest Standardization](./phases/phase-3-manifest.md)
- [Phase 4 — Build & Scaffolding Flow](./phases/phase-4-build-flow.md)
- [Phase 5 — Registration Cleanup](./phases/phase-5-registration.md)
- [Phase 6 — Docs & Migration](./phases/phase-6-docs-migration.md)
- [Phase 7 — Optional Overrides (Advanced)](./phases/phase-7-overrides.md)

## Implementation Notes

- Treat this folder as the source of truth for extension refactor sequencing.
- Keep scope incremental and reversible by phase.
- Add implementation PR links to each phase doc as work begins.
