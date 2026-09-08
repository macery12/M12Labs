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
    ];

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
