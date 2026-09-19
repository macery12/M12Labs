<?php

namespace Everest\Services\Extensions\Manifest;

/**
 * The closed vocabularies a manifest may draw on.
 *
 * Every list here is an allowlist: a manifest naming anything outside them is
 * rejected rather than ignored, so a package cannot quietly acquire a surface
 * the panel does not know how to gate. The icon and category sets mirror the
 * frontend (pages/admin/extensions/extMeta.ts and routes/registry.ts); they are
 * duplicated deliberately so validation happens server-side, where a package
 * cannot reach, rather than at render time.
 */
final class ExtensionCapabilityVocabulary
{
    /** Top-level manifest keys. Anything else is a rejection. */
    public const MANIFEST_KEYS = [
        'manifestVersion',
        'package',
        'extension',
        'compatiblePanelVersions',
        'capabilities',
        'requirements',
        'files',
        'integrity',
    ];

    /** Executable/privileged surfaces. Unknown keys are rejected, not ignored. */
    public const CAPABILITY_KEYS = [
        'routes',
        'pages',
        'permissions',
        'database',
        'hooks',
        'queues',
        'schedule',
        'commands',
        'secrets',
        'settings',
        'privileged',
        'bindings',
        'streams',
        'slots',
    ];

    /**
     * Panel-owned frontend locations a package may contribute to.
     *
     * This starts deliberately small. Both locations are inside the loaded
     * server context: banners render immediately before routed server content;
     * overlays render beside the shell and may use fixed positioning for
     * drawers, launchers and other companions that must survive navigation.
     * Checkout, auth and public surfaces need separate threat models and are
     * not expressible here.
     */
    public const FRONTEND_SLOTS = [
        'server-layout.banner',
        'server-layout.overlay',
    ];

    /**
     * A class a package asks the container to build once per request, written
     * as its path inside the package: `Tools/ToolCatalogue` becomes
     * `app/Extensions/Packages/<id>/Tools/ToolCatalogue.php` and the class
     * `Everest\Extensions\Packages\<id>\Tools\ToolCatalogue`.
     *
     * Deriving both from the declaration is what keeps a package binding only
     * its own classes — the same rule the rest of the manifest follows, where a
     * package never names a file itself. There is no spelling of this that
     * reaches a core service or another extension's, so nothing has to check.
     *
     * StudlyCase segments only: no dots, no leading slash, nothing that could
     * traverse.
     */
    public const BINDING_PATTERN = '/^[A-Z][A-Za-z0-9]*(\/[A-Z][A-Za-z0-9]*)*$/';

    /**
     * Ceilings on a declared stream, applied while parsing the manifest.
     *
     * These bound what an author may write down. They are not the operating
     * limit: `config('extensions.streams')` clamps again at runtime, so an
     * operator can tighten a deployment below what its installed packages
     * asked for without reinstalling any of them.
     */
    public const STREAM_MAX_SECONDS = 3600;

    public const STREAM_MAX_KEEPALIVE_SECONDS = 60;

    public const STREAM_MAX_CONCURRENT_PER_USER = 20;

    /**
     * Absolute ceiling on a declared job timeout, before the lane's own limit
     * is applied on top.
     *
     * It matches `supervisor-extensions-long`'s Horizon timeout rather than the
     * long connection's `retry_after` (3900): Horizon force-kills a worker it
     * considers hung, so a job allowed past the supervisor's timeout would be
     * killed mid-run on every attempt instead of finishing.
     *
     * The operating limit is lower than this on the short lane. `retry_after`
     * there is 300, and the parser refuses anything that would outlive it —
     * see ExtensionManifestParser::parseQueues().
     */
    public const QUEUE_MAX_TIMEOUT_SECONDS = 3600;

    /**
     * Privileged core services a package may be granted, as opposed to surfaces
     * it declares. Everything else in this vocabulary describes something the
     * package *contributes* — a route, a page, a queue — which core then runs.
     * These are the other direction: core behaviour the package calls into,
     * where the thing being handed over is authority rather than a slot.
     *
     * Each is off unless the manifest names it and an administrator approves it
     * at install. Closed, and deliberately short — a name here is a decision
     * that the capability is worth existing at all.
     *
     * - `delegated_access` — ask core to open an audited, read-only session on a
     *   customer's server for an administrator who holds `servers.assist`. Core
     *   owns the ability list and writes the customer-visible record; see
     *   {@see \Everest\Services\Access\DelegatedAccess}.
     * - `internal_dispatch` — run a request through the panel's own HTTP
     *   pipeline as the acting user, without re-authenticating. Powerful and
     *   deliberately so: it is what lets a package reuse every FormRequest gate
     *   and middleware rather than growing a second authorization path. It is
     *   also a confused-deputy generator, which is why it is named here.
     */
    public const PRIVILEGED = ['delegated_access', 'internal_dispatch'];

    /** Slugs, permission actions and queue names. */
    public const SLUG_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /** Extension ids: snake_case, matching the install directory name. */
    public const EXTENSION_ID_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    /**
     * Declared page categories.
     *
     * Accepted and recorded, but they no longer decide sidebar placement: both
     * frontend route generators file every extension page under the Extensions
     * section, so an installed package cannot interleave its screens with the
     * panel's own and leave an operator unable to tell core from third-party.
     * What survives is ordering metadata within that section.
     */
    public const SERVER_CATEGORIES = ['general', 'data', 'configuration'];

    public const ADMIN_CATEGORIES = ['general', 'access', 'developers', 'modules', 'management', 'extensions'];

    /**
     * Hook delivery modes.
     *
     * `synchronous_required` is deliberately absent: PHP cannot preempt a
     * handler, so a declared timeout is a budget rather than an enforcement,
     * and an in-process hook able to abort a core operation has no bounded
     * blast radius. The parser recognises the value only to reject it with an
     * explanation instead of an "unknown mode" error.
     */
    public const HOOK_MODES = ['synchronous_best_effort', 'queued_at_least_once'];

    public const REJECTED_HOOK_MODES = ['synchronous_required'];

    /** Core events an extension may subscribe to. */
    public const HOOK_EVENTS = [
        'server.created',
        'server.updated',
        'server.allocation_changed',
        'server.pre_delete',
    ];

    /**
     * Settings field types.
     *
     * `password` is absent by design: extension_configs.settings is a plain
     * JSON column the catalog API returns, so a secret placed there would be
     * readable. Secrets are declared under `capabilities.secrets` and stored
     * encrypted instead.
     */
    public const SETTING_TYPES = ['text', 'textarea', 'select', 'boolean', 'number', 'url', 'host'];

    public const SETTING_VISIBILITIES = ['public', 'admin', 'secret'];

    /** Icon slugs shared with the frontend icon map. */
    public const ICONS = [
        'puzzle', 'bot', 'ai', 'shield', 'shieldcheck', 'auth', 'security', 'mail', 'email',
        'webhook', 'webhooks', 'billing', 'creditcard', 'payment', 'globe', 'domain', 'domains',
        'ticket', 'tickets', 'support', 'bell', 'alert', 'alerts', 'notification', 'database',
        'db', 'server', 'node', 'users', 'user', 'player', 'players', 'playermanager', 'gamepad',
        'key', 'api', 'book', 'docs', 'palette', 'theme', 'box', 'boxes', 'marketplace', 'mods',
        'zap', 'discord', 'discordsrv', 'chat', 'message', 'map', 'wrench', 'tools', 'plug',
    ];

    /**
     * Panel services a package may declare a dependency on. Declaring one is a
     * statement of intent that the installer can surface, not a grant.
     */
    public const PANEL_SERVICES = ['http-client', 'queue', 'schedule', 'secrets', 'hooks'];
}
