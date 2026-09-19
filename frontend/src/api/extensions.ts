import http from '@/lib/http';

// Admin extension catalog view-models. Mirrors the shapes emitted by
// ExtensionCatalogService (app/Services/Extensions). The application API
// (/api/application/extensions) is session-authed same-origin, so the shared
// http client works as-is. Strings sourced from a manifest (name, description,
// author, source label, security warning, settings-schema labels) are rendered
// verbatim and intentionally NOT routed through the i18n catalog.

export type ExtensionStatus = 'core' | 'installed' | 'available' | 'unsupported';
// Persisted lifecycle state. Anything outside 'enabled' | 'installed_disabled'
// is inert: the runtime gate will not load it and the API refuses to enable it.
export type ExtensionLifecycleState =
    | 'installed_disabled'
    | 'enabled'
    | 'unsupported'
    | 'failed'
    | 'installing'
    | 'enabling'
    | 'disabling'
    | 'updating'
    | 'uninstalling'
    | 'staged';
export type ExtensionType = 'user' | 'admin' | 'both';

export interface ExtensionSource {
    type: 'core' | 'repository';
    label: string;
    official: boolean;
    repositoryId: number | null;
    repositoryName: string | null;
    homepageUrl: string | null;
    securityWarning: string | null;
}

// A single field in an extension's settings schema (manifest-defined).
export interface ExtensionSettingField {
    key: string;
    label: string;
    type: string; // text | textarea | number | boolean | select | url | host
    description?: string;
    placeholder?: string;
    default?: unknown;
    required?: boolean;
    options?: Array<{ value: string; label: string }>;
    // v3 packages ship copy in their own translation catalog, which the panel
    // cannot resolve server-side; the keys travel and `td()` resolves them, with
    // `label`/`description` as the fallback.
    labelKey?: string;
    helpKey?: string;
}

/**
 * Counts a repository advertises for a package, rendered on the catalog card
 * with an "as advertised by the repository — verified at install" caption.
 */
export interface ExtensionCapabilitySummary {
    serverPages: number;
    adminPages: number;
    clientRoutes: boolean;
    adminRoutes: boolean;
    migrations: boolean;
    schedule: boolean;
    commands: number;
    hooks: string[];
    queues: number;
    permissions: number;
    secrets: number;
    settings: number;
    // Names, not a count: "asks for 1 privileged service" tells an operator
    // nothing they can act on. Absent on packages that ask for none, and on
    // repositories that predate the field.
    privileged?: string[];
    // Classes the package asks the container to build once per request. An
    // optimisation, not a privilege — a count is all an operator needs.
    bindings?: number;
    // Long-lived connections the package may hold open. Counted rather than
    // named here; the install dialog spells each one out with its limits,
    // because how long a stream may run is the part worth reading.
    streams?: number;
    // Components mounted into panel-owned global layout locations.
    slots?: number;
}

export interface Extension {
    id: string;
    name: string;
    description: string;
    version: string;
    latestVersion: string;
    author: string;
    icon: string;
    route: string;
    // Surface type derived by the panel from the manifest. 'user' = per-server
    // page, 'admin' = admin page only (no per-server access scoping), 'both'.
    type: ExtensionType;
    hasServerPage: boolean;
    // What a repository ADVERTISES a package contains, for the catalog card of
    // something not yet installed. Never a capability the panel acts on:
    // registry metadata is unauthenticated, and the panel gates on the signed
    // manifest inside the archive instead. Null for installed and core
    // extensions, whose real projection is already known.
    capabilitySummary?: ExtensionCapabilitySummary | null;
    enabled: boolean;
    allowedNests: number[];
    allowedEggs: number[];
    settings: Record<string, unknown>;
    settingsSchema: ExtensionSettingField[];
    installed: boolean;
    installable: boolean;
    canUninstall: boolean;
    // True when the package ships migrations (i.e. it created database tables).
    // The uninstall drawer only offers the drop-tables option when this is set.
    hasDatabase: boolean;
    status: ExtensionStatus;
    // Present for installed packages only. `canEnable` is false while the
    // package is quarantined, in which case `stateReason` explains why.
    state?: ExtensionLifecycleState;
    stateReason?: string | null;
    manifestVersion?: number;
    canEnable?: boolean;
    updateAvailable: boolean;
    // False only for an *available* (repository) extension whose declared
    // compatiblePanelVersions exclude the running panel. Installed/core/manual
    // extensions are always true — compatibility gates repo fetches, not what's
    // already on disk. Drives the "incompatible" badge + a blocked Install button.
    compatible: boolean;
    compatiblePanelVersions: string[];
    source: ExtensionSource;
}

export interface Repository {
    id: number;
    slug: string;
    name: string;
    manifestUrl: string;
    homepageUrl: string | null;
    enabled: boolean;
    official: boolean;
    packagesCount: number;
    securityWarning: string | null;
    status?: 'ok' | 'disabled' | 'error';
    error?: string;
}

export interface OperationProgress {
    action: string; // install | uninstall | update | batch-install | …
    extension_id: string;
    stage: string;
    started_at: string;
    updated_at: string;
    batch_total?: number;
    batch_current?: number;
    batch_extensions?: string[];
}

export interface NestOption {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
}

export interface EggOption {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
    nestId: number;
    nestName: string;
}

const BASE = '/api/application/extensions';

// GET /extensions — the full catalog (core + installed + available).
export async function getExtensions(): Promise<Extension[]> {
    const { data } = await http.get(BASE);
    return (data.data ?? []) as Extension[];
}

// GET /extensions/repositories — configured repositories + their health.
export async function getRepositories(): Promise<Repository[]> {
    const { data } = await http.get(`${BASE}/repositories`);
    return (data.data ?? []) as Repository[];
}

// POST /extensions/refresh — bust the manifest cache and return fresh extensions.
export async function refreshCatalog(): Promise<Extension[]> {
    const { data } = await http.post(`${BASE}/refresh`);
    return (data.data ?? []) as Extension[];
}

// GET /extensions/nests-eggs — nests + eggs for the access-control picker.
export async function getNestsAndEggs(): Promise<{ nests: NestOption[]; eggs: EggOption[] }> {
    const { data } = await http.get(`${BASE}/nests-eggs`);
    return { nests: data.nests ?? [], eggs: data.eggs ?? [] };
}

// GET /extensions/progress — current install/uninstall/update stage (null when idle).
export async function getProgress(): Promise<OperationProgress | null> {
    const { data } = await http.get(`${BASE}/progress`);
    return (data.progress ?? null) as OperationProgress | null;
}

export interface UpdateExtensionPayload {
    enabled?: boolean;
    allowedNests?: number[];
    allowedEggs?: number[];
    settings?: Record<string, unknown>;
}

// PUT /extensions/{id} — persist config (enabled, access, settings).
export async function updateExtension(id: string, payload: UpdateExtensionPayload): Promise<Extension> {
    const { data } = await http.put(`${BASE}/${id}`, {
        enabled: payload.enabled,
        allowed_nests: payload.allowedNests,
        allowed_eggs: payload.allowedEggs,
        settings: payload.settings,
    });
    return data.attributes as Extension;
}

// POST /extensions/{id}/toggle — flip enabled state.
export async function toggleExtension(id: string): Promise<Extension> {
    const { data } = await http.post(`${BASE}/${id}/toggle`);
    return data.attributes as Extension;
}

// The privileges a release asks for, relative to what is installed. Returned
// by a 409 when an install or update would grant something not yet approved.
export interface CapabilityDiff {
    added: string[];
    removed: string[];
    // The subset of `added` that actually widens reach — routes, permissions,
    // hooks, queues, secrets, commands, tables, migrations, schedules. Adding a
    // page or a setting is reported but grants nothing new.
    escalations: string[];
    isEscalation: boolean;
    // Consent token. Sent back with the retried request; it changes whenever
    // the capabilities do, so an approval cannot transfer to a different set.
    hash: string;
}

export class CapabilityApprovalRequired extends Error {
    constructor(
        public readonly extensionId: string,
        public readonly diff: CapabilityDiff,
    ) {
        super('This package requests capabilities that have not been approved.');
        this.name = 'CapabilityApprovalRequired';
    }
}

/**
 * Raised when the operation would discard local edits to installed files.
 *
 * Legitimate drift is common — a code formatter run over the panel tree
 * rewrites installed package PHP — and until this is acknowledged the package
 * can be neither updated nor removed.
 */
export class ModifiedFilesRequireAcknowledgement extends Error {
    constructor(
        public readonly extensionId: string,
        public readonly verb: string,
        public readonly paths: string[],
        message: string,
    ) {
        super(message);
        this.name = 'ModifiedFilesRequireAcknowledgement';
    }
}

export interface PackageRequirementProblem {
    type: 'frontend' | 'backend';
    manager: 'npm' | 'composer';
    package: string;
    required: string;
    installed: string | null;
    status: 'missing' | 'incompatible';
}

export interface PackageRequirementFailure {
    problems: PackageRequirementProblem[];
    commands: Partial<Record<'npm' | 'composer', string>>;
}

/**
 * The verified extension manifest asks for panel dependencies that are not
 * available at a compatible locked version. The UI explains how to install
 * them manually; extension lifecycle code never executes these commands.
 */
export class PackageRequirementsNotSatisfied extends Error {
    constructor(
        public readonly extensionId: string,
        public readonly requirements: PackageRequirementFailure,
        message: string,
    ) {
        super(message);
        this.name = 'PackageRequirementsNotSatisfied';
    }
}

// The panel computes the diff from the VERIFIED manifest, so it can only answer
// after downloading and checking the archive — which is why approval is a 409
// on the real request rather than a separate preflight endpoint.
//
// The modified-files conflict arrives the same way and for the same reason: it
// is only knowable once the package's files are compared on disk.
function rethrowExtensionConflicts(error: unknown): never {
    const response = (error as { response?: { status?: number; data?: Record<string, unknown> } }).response;

    if (response?.status === 409 && response.data?.capability_diff) {
        throw new CapabilityApprovalRequired(
            String(response.data.extension_id ?? ''),
            response.data.capability_diff as CapabilityDiff,
        );
    }

    if (response?.status === 409 && response.data?.modified_files) {
        const modified = response.data.modified_files as { verb: string; paths: string[] };

        throw new ModifiedFilesRequireAcknowledgement(
            String(response.data.extension_id ?? ''),
            modified.verb,
            modified.paths ?? [],
            String(response.data.error ?? 'Files were modified after installation.'),
        );
    }

    if (response?.data?.package_requirements) {
        throw new PackageRequirementsNotSatisfied(
            String(response.data.extension_id ?? ''),
            response.data.package_requirements as unknown as PackageRequirementFailure,
            String(response.data.error ?? 'This extension requires packages that are missing or incompatible.'),
        );
    }

    throw error;
}

// POST /extensions/{id}/install — install a repository-backed package.
export async function installExtension(
    id: string,
    repositoryId: number,
    version?: string,
    approvedCapabilityHash?: string,
): Promise<Extension> {
    try {
        const { data } = await http.post(`${BASE}/${id}/install`, {
            repository_id: repositoryId,
            version,
            approved_capability_hash: approvedCapabilityHash,
        });
        return data.attributes as Extension;
    } catch (error) {
        rethrowExtensionConflicts(error);
    }
}

// POST /extensions/{id}/update-package — update an installed package to a newer version.
export async function updateExtensionPackage(
    id: string,
    repositoryId: number,
    version?: string,
    approvedCapabilityHash?: string,
    acknowledgeModifiedFiles?: boolean,
): Promise<Extension> {
    try {
        const { data } = await http.post(`${BASE}/${id}/update-package`, {
            repository_id: repositoryId,
            version,
            approved_capability_hash: approvedCapabilityHash,
            acknowledge_modified_files: acknowledgeModifiedFiles,
        });
        return data.attributes as Extension;
    } catch (error) {
        rethrowExtensionConflicts(error);
    }
}

export interface UninstallResult {
    extension: Extension;
    // Database tables are preserved by default; dropData rolls the extension's
    // migrations back server-side after an explicit typed confirmation.
    dataDropped: boolean;
    preservedTables: string[];
    manualCleanup: string[];
    possiblyUnusedPackages: PossiblyUnusedPackages;
}

export interface PossiblyUnusedPackages {
    npmPackages: string[];
    composerPackages: string[];
    commands: Partial<Record<'npm' | 'composer', string>>;
}

function possiblyUnusedPackages(data: unknown): PossiblyUnusedPackages {
    const value = (data ?? {}) as {
        npm_packages?: string[];
        composer_packages?: string[];
        commands?: Partial<Record<'npm' | 'composer', string>>;
    };

    return {
        npmPackages: value.npm_packages ?? [],
        composerPackages: value.composer_packages ?? [],
        commands: value.commands ?? {},
    };
}

// POST /extensions/{id}/uninstall — remove an installed package. Pass dropData
// (with confirm === id) to also drop the extension's database tables.
export async function uninstallExtension(
    id: string,
    dropData = false,
    confirm?: string,
    acknowledgeModifiedFiles?: boolean,
): Promise<UninstallResult> {
    let data;
    try {
        ({ data } = await http.post(`${BASE}/${id}/uninstall`, {
            ...(dropData ? { drop_data: true, confirm } : {}),
            acknowledge_modified_files: acknowledgeModifiedFiles,
        }));
    } catch (error) {
        rethrowExtensionConflicts(error);
    }

    return {
        extension: data.attributes as Extension,
        dataDropped: Boolean(data.meta?.data_dropped),
        preservedTables: (data.meta?.preserved_tables ?? []) as string[],
        manualCleanup: (data.meta?.manual_cleanup ?? []) as string[],
        possiblyUnusedPackages: possiblyUnusedPackages(data.meta?.possibly_unused_packages),
    };
}

export type DatabasePlanOperation = 'install' | 'update' | 'uninstall';

// Read-only preview of the database changes an install/update/uninstall would
// make, from POST /extensions/{id}/database-plan. `hasDatabase` is false when
// the operation touches no tables (frontend then skips the DB section).
export interface DatabasePlan {
    operation: DatabasePlanOperation;
    extensionId: string;
    tablePrefix: string;
    hasDatabase: boolean;
    version?: string;
    // install / update — what the archive's pending migrations say they will do.
    // Every schema verb is read, not just Schema::create: an update whose
    // migration drops a table used to produce a plan listing only what it added.
    tablesToCreate?: string[];
    tablesToAlter?: string[];
    tablesToDrop?: string[];
    tablesToRename?: { from: string; to: string }[];
    // Row counts for the tables above that exist right now, so a drop can be
    // shown as what it costs. Absent for a table this panel has never seen,
    // which is deliberately not the same as zero.
    rowCounts?: Record<string, number>;
    // Statements (raw SQL) whose effect could not be read from the source. Shown
    // so an incomplete list is not read as an exhaustive one.
    unanalysedStatements?: number;
    migrations?: string[];
    // update — tables the extension already owns that stay in place. Excludes
    // any this update drops or renames away; those have their own heading.
    unchangedTables?: string[];
    // uninstall — tables/migrations the extension currently owns (dropped when
    // drop-data is confirmed, otherwise preserved) + manual cleanup SQL.
    existingTables?: string[];
    ranMigrations?: string[];
    manualCleanup?: string[];
    // uninstall — how many role assignments of this extension's admin
    // permissions will be removed. Reinstalling restores the permissions but
    // not the grants.
    roleAssignments?: number;
}

// Computed runtime state for one extension. Nothing here is stored: it is
// derived on read from the package row, the capability tables, the migration
// log and the built asset manifest.
export interface ExtensionHealth {
    id: string;
    installed: boolean;
    version?: string;
    state?: string;
    stateReason?: string | null;
    manifestVersion?: number;
    // An extension can be enabled and still not load — loading also needs an
    // executable state, an intact capability projection and an acceptable
    // signature. This is the field that says which.
    loadable?: boolean;
    /** Which runtime check refused the extension, when loadable is false. */
    notLoadableReason?: string | null;
    signature?: {
        state: string;
        keyId: string | null;
        verifiedAt: string | null;
        enforced: boolean;
    };
    capabilities?: Record<string, unknown>;
    integrity?: {
        trackedFiles: number;
        missingFiles: string[];
        modifiedFiles: string[];
        capabilityProjectionMatches: boolean;
    };
    database?: { tablePrefix: string; tables: string[]; ranMigrations: string[] };
    permissions?: { declared: number; pendingApproval: number; suspended: number; identifiers: string[] };
    queues?: {
        byStatus: Record<string, number>;
        lastFailure: { jobClass: string; queue: string; at: string | null; error: string | null } | null;
    };
    hooks?: Array<{
        event: string;
        handler: string;
        invocations: number;
        failures: number;
        consecutiveFailures: number;
        averageMs: number;
        breakerOpen: boolean;
        quarantined: boolean;
        lastError: string | null;
    }>;
    secrets?: Array<{ key: string; configured: boolean; version: number; updatedAt: string | null }>;
    assets?: { built: boolean; entries: number };
}

// GET /extensions/{id}/health
export async function getExtensionHealth(id: string): Promise<ExtensionHealth> {
    const { data } = await http.get(`${BASE}/${id}/health`);
    return data.attributes as ExtensionHealth;
}

// GET /extensions/{id}/health/export — the same report, redacted for sharing.
export async function getExtensionHealthExport(id: string): Promise<unknown> {
    const { data } = await http.get(`${BASE}/${id}/health/export`);
    return data;
}

// Metadata for one declared credential. The value is never returned — the API
// only says whether something is stored and when it last changed.
export interface ExtensionSecret {
    key: string;
    labelKey: string;
    helpKey: string | null;
    rotatable: boolean;
    configured: boolean;
    updatedAt: string | null;
    rotatedAt: string | null;
}

// GET /extensions/{id}/secrets
export async function getExtensionSecrets(id: string): Promise<ExtensionSecret[]> {
    const { data } = await http.get(`${BASE}/${id}/secrets`);
    return (data.data ?? []) as ExtensionSecret[];
}

// PUT /extensions/{id}/secrets/{key} — write-only. An empty value means
// "unchanged", so a blind form cannot wipe a working credential.
export async function putExtensionSecret(id: string, key: string, value: string): Promise<ExtensionSecret[]> {
    const { data } = await http.put(`${BASE}/${id}/secrets/${encodeURIComponent(key)}`, { value });
    return (data.data ?? []) as ExtensionSecret[];
}

// DELETE /extensions/{id}/secrets/{key}
export async function deleteExtensionSecret(id: string, key: string): Promise<ExtensionSecret[]> {
    const { data } = await http.delete(`${BASE}/${id}/secrets/${encodeURIComponent(key)}`);
    return (data.data ?? []) as ExtensionSecret[];
}

// POST /extensions/{id}/database-plan — preview DB changes before committing.
// install/update need the source repository (and optional version) to fetch
// and parse the archive's migrations; uninstall reads local state.
export async function getDatabasePlan(
    id: string,
    operation: DatabasePlanOperation,
    opts?: { repositoryId?: number; version?: string },
): Promise<DatabasePlan> {
    const { data } = await http.post(`${BASE}/${id}/database-plan`, {
        operation,
        repository_id: opts?.repositoryId,
        version: opts?.version,
    });
    return data.attributes as DatabasePlan;
}

export interface RepositoryPayload {
    name: string;
    manifestUrl?: string;
    homepageUrl?: string | null;
    enabled?: boolean;
    // Only required when adding a repository: the operator must acknowledge that
    // a repository can run arbitrary code. The backend enforces `required|accepted`.
    acknowledgeRisk?: boolean;
}

// POST /extensions/repositories — register a new repository.
export async function storeRepository(payload: RepositoryPayload): Promise<Repository> {
    const { data } = await http.post(`${BASE}/repositories`, {
        name: payload.name,
        manifest_url: payload.manifestUrl,
        homepage_url: payload.homepageUrl,
        enabled: payload.enabled,
        acknowledge_risk: payload.acknowledgeRisk,
    });
    return data.attributes as Repository;
}

// PATCH /extensions/repositories/{id} — edit an existing repository.
export async function updateRepository(id: number, payload: RepositoryPayload): Promise<Repository> {
    const { data } = await http.patch(`${BASE}/repositories/${id}`, {
        name: payload.name,
        manifest_url: payload.manifestUrl,
        homepage_url: payload.homepageUrl,
        enabled: payload.enabled,
    });
    return data.attributes as Repository;
}

// DELETE /extensions/repositories/{id} — remove a custom repository.
export async function deleteRepository(id: number): Promise<void> {
    await http.delete(`${BASE}/repositories/${id}`);
}

// A single item in a batch install/update payload.
export interface BatchInstallItem {
    extensionId: string;
    repositoryId: number;
    version?: string;
    approvedCapabilityHash?: string;
    acknowledgeModifiedFiles?: boolean;
}

// POST /extensions/batch-install — install several packages in one rebuild.
export async function batchInstallExtensions(items: BatchInstallItem[]): Promise<Extension[]> {
    try {
        const { data } = await http.post(`${BASE}/batch-install`, {
            extensions: items.map(i => ({
                extension_id: i.extensionId,
                repository_id: i.repositoryId,
                version: i.version,
                approved_capability_hash: i.approvedCapabilityHash,
            })),
        });
        return (data.data ?? []) as Extension[];
    } catch (error) {
        rethrowExtensionConflicts(error);
    }
}

// A per-extension opt-in to drop data during a batch uninstall. `confirm` must
// equal the extension id, matching the single-uninstall typed confirmation.
export interface BatchDropDataItem {
    id: string;
    confirm: string;
}

// POST /extensions/batch-uninstall — remove several packages in one rebuild.
// Data is preserved by default; pass `dropData` entries (each confirmed with
// its own id) to also drop those extensions' tables, each audited separately.
export async function batchUninstallExtensions(
    extensionIds: string[],
    dropData?: BatchDropDataItem[],
): Promise<{ extensions: Extension[]; possiblyUnusedPackages: PossiblyUnusedPackages }> {
    const { data } = await http.post(`${BASE}/batch-uninstall`, {
        extension_ids: extensionIds,
        ...(dropData && dropData.length ? { drop_data: dropData } : {}),
    });
    return {
        extensions: (data.data ?? []) as Extension[],
        possiblyUnusedPackages: possiblyUnusedPackages(data.meta?.possibly_unused_packages),
    };
}

// POST /extensions/batch-update — update several packages in one rebuild.
export async function batchUpdateExtensions(items: BatchInstallItem[]): Promise<Extension[]> {
    try {
        const { data } = await http.post(`${BASE}/batch-update`, {
            extensions: items.map(i => ({
                extension_id: i.extensionId,
                repository_id: i.repositoryId,
                version: i.version,
                approved_capability_hash: i.approvedCapabilityHash,
                acknowledge_modified_files: i.acknowledgeModifiedFiles,
            })),
        });
        return (data.data ?? []) as Extension[];
    } catch (error) {
        rethrowExtensionConflicts(error);
    }
}
