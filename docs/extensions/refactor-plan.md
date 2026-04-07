# Extension System Refactor Plan

## 1) Audit Summary of Existing System

### Backend extension loading

- Client extension routes are discovered by requiring all files under `routes/extensions/client/*.php`.
- Extension availability to end users is determined from DB-backed `ExtensionConfig` records plus server eligibility checks.
- Access is enforced by request permissions and extension middleware (`extensions.access`).

### Frontend extension registration

- Server extension pages are hardcoded in `resources/scripts/components/server/extensions/registry.ts`.
- Server sidebar exposure depends on `extensions_enabled` in server transformer output.
- Extension API modules are manually wired by extension (for example `api/server/extensions/playerManager.ts`).

### Config/manifests

- Extension metadata currently lives in `config/modules/extensions.php`.
- Runtime per-extension state is split into DB (`extension_configs`) and config defaults.
- Some metadata and behavior are duplicated across config, routes, controllers, TS registry, and API clients.

### Permissions and route wiring

- Generic extension permissions: `extension.read`, `extension.manage`.
- Several extension endpoints also require file-level permissions.
- Route and authorization logic are spread across route files, middleware, request classes, and controllers.

### Current extension examples

- `minecraft_player_manager`
- `discordsrv_helper`

### Key friction points

- Cross-stack registration is manual and duplicated.
- Discovery/registration is partly implicit (globbed backend routes) and partly explicit (frontend registry).
- Authoring flow for fork maintainers is not centralized in docs.
- Some config-level concepts are not consistently enforced as a single contract.

---

## 2) Proposed Target Architecture

A **feature-module architecture** with explicit registration and build-time inclusion.

### Core principles

- Extensions are first-party feature modules, not runtime plugin packages.
- Every extension has a clear boundary and predictable file layout.
- Metadata is standardized via a manifest contract.
- Registration is explicit and discoverable in one place per layer.
- Fork users should be able to add an extension without touching many unrelated files.

### Target shape

- One extension directory per extension (backend + frontend mirrors).
- One manifest definition per extension with standardized fields.
- Centralized registries generated or validated against manifest data.
- Optional scaffolding command/script to bootstrap new extension files.

---

## 3) Phased Refactor Path

## Phase 1 — Audit & Inventory

See: [phase-1-audit.md](./phases/phase-1-audit.md)

## Phase 2 — Structure Normalization

See: [phase-2-structure.md](./phases/phase-2-structure.md)

## Phase 3 — Manifest Standardization

See: [phase-3-manifest.md](./phases/phase-3-manifest.md)

## Phase 4 — Build & Scaffolding Flow

See: [phase-4-build-flow.md](./phases/phase-4-build-flow.md)

## Phase 5 — Registration Cleanup

See: [phase-5-registration.md](./phases/phase-5-registration.md)

## Phase 6 — Docs & Migration Guidance

See: [phase-6-docs-migration.md](./phases/phase-6-docs-migration.md)

## Phase 7 — Optional Override Support (Advanced)

See: [phase-7-overrides.md](./phases/phase-7-overrides.md)

---

## 4) Migration Path (Concise)

1. Inventory existing extension IDs, routes, permissions, and frontend pages.
2. Introduce normalized extension folders while preserving old entry points.
3. Define and validate manifest contract against current config.
4. Add scaffold/build helpers for new extension creation in forks.
5. Move to explicit, single-path registration patterns.
6. Publish migration docs and deprecation notes for old wiring.
7. Keep override support optional, guarded, and clearly marked as advanced.

---

## 5) Risks & Warnings

- Breaking route/permission behavior during consolidation.
- Drift between old and new registration during transition.
- Overengineering toward runtime plugin behavior (explicitly out of scope).
- Override mechanisms can create fragile upgrade paths if treated as default.

---

## 6) Long-Term Developer Experience Vision

- Add extension via a scaffold command/template.
- Fill one manifest.
- Implement backend handlers in extension module folder.
- Implement frontend container in extension module folder.
- Register once through explicit registry flow.
- Follow one doc page for fork-safe extension development.
