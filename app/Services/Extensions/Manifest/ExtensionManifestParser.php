<?php

namespace Everest\Services\Extensions\Manifest;

use Illuminate\Support\Str;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * Strict manifest v3 parser.
 *
 * Two rules shape everything here:
 *
 *   Missing means denied. A capability the manifest does not declare is not
 *   inferred from files on disk, so a package cannot acquire a surface the
 *   administrator never approved.
 *
 *   Unknown means rejected. An unrecognised key is an error rather than
 *   something to ignore, so a manifest written for a future panel fails loudly
 *   instead of installing with a silently missing gate.
 *
 * There is no v1/v2 acceptance path: those manifests carry no capability block
 * at all, so every surface would have to be inferred, which is precisely the
 * behaviour this replaces.
 */
class ExtensionManifestParser
{
    /**
     * @param array<string, mixed> $manifest
     *
     * @throws DisplayException
     */
    public function parse(array $manifest, ?string $expectedExtensionId = null, ?string $expectedVersion = null): ExtensionManifest
    {
        $this->assertKnownKeys($manifest, ExtensionCapabilityVocabulary::MANIFEST_KEYS, 'manifest');

        $manifestVersion = $manifest['manifestVersion'] ?? null;
        if (!is_int($manifestVersion) || $manifestVersion !== ExtensionManifest::VERSION) {
            throw new DisplayException(sprintf('This package declares manifest version %s. This panel requires manifest version %d.', is_scalar($manifestVersion) ? var_export($manifestVersion, true) : 'none', ExtensionManifest::VERSION));
        }

        $package = $this->section($manifest, 'package');
        $extension = $this->section($manifest, 'extension');

        $id = $this->string($extension, 'id', 'extension.id');
        if (!preg_match(ExtensionCapabilityVocabulary::EXTENSION_ID_PATTERN, $id)) {
            throw new DisplayException(sprintf('The extension id "%s" is invalid. Use lowercase letters, digits and underscores.', $id));
        }

        // Identity must agree everywhere it appears; the id drives the install
        // directory, PHP namespace, table prefix, route prefix, translation
        // prefix and permission prefix, so a mismatch is a boundary violation.
        $packageId = $this->string($package, 'id', 'package.id');
        if ($packageId !== $id) {
            throw new DisplayException(sprintf('The manifest package id "%s" does not match the extension id "%s".', $packageId, $id));
        }

        if ($expectedExtensionId !== null && $id !== $expectedExtensionId) {
            throw new DisplayException('The downloaded package does not match the requested extension id.');
        }

        $version = $this->string($package, 'version', 'package.version');
        if ($expectedVersion !== null && $version !== $expectedVersion) {
            throw new DisplayException('The downloaded package version does not match the repository manifest.');
        }

        $capabilities = $this->parseCapabilities($manifest['capabilities'] ?? [], $id);

        return new ExtensionManifest(
            id: $id,
            version: $version,
            packageId: $packageId,
            name: $this->string($extension, 'name', 'extension.name'),
            description: trim((string) ($extension['description'] ?? '')),
            icon: $this->icon($extension['icon'] ?? 'puzzle', 'extension.icon'),
            publisher: isset($package['publisher']) ? (string) $package['publisher'] : null,
            license: isset($package['license']) ? (string) $package['license'] : null,
            homepage: isset($package['homepage']) ? (string) $package['homepage'] : null,
            defaults: $this->parseDefaults($extension['defaults'] ?? [], $capabilities),
            compatiblePanelVersions: $this->parseCompatibility($manifest['compatiblePanelVersions'] ?? []),
            capabilities: $capabilities,
            requirements: $this->parseRequirements($manifest['requirements'] ?? []),
            files: $this->parseFiles($manifest['files'] ?? []),
            integrity: $this->parseIntegrity($manifest['integrity'] ?? null),
            raw: $manifest,
        );
    }

    private function parseCapabilities($capabilities, string $extensionId): ExtensionCapabilitySet
    {
        if (!is_array($capabilities)) {
            throw new DisplayException('The manifest "capabilities" section must be an object.');
        }

        $this->assertKnownKeys($capabilities, ExtensionCapabilityVocabulary::CAPABILITY_KEYS, 'capabilities');

        $routes = $capabilities['routes'] ?? [];
        if (!is_array($routes)) {
            throw new DisplayException('The manifest "capabilities.routes" section must be an object.');
        }
        $this->assertKnownKeys($routes, ['client', 'admin'], 'capabilities.routes');

        $pages = $capabilities['pages'] ?? [];
        if (!is_array($pages)) {
            throw new DisplayException('The manifest "capabilities.pages" section must be an object.');
        }
        $this->assertKnownKeys($pages, ['server', 'admin'], 'capabilities.pages');

        $permissions = $capabilities['permissions'] ?? [];
        if (!is_array($permissions)) {
            throw new DisplayException('The manifest "capabilities.permissions" section must be an object.');
        }
        $this->assertKnownKeys($permissions, ['admin'], 'capabilities.permissions');

        $database = $capabilities['database'] ?? [];
        if (!is_array($database)) {
            throw new DisplayException('The manifest "capabilities.database" section must be an object.');
        }
        $this->assertKnownKeys($database, ['migrations', 'tables'], 'capabilities.database');

        $adminPermissions = $this->parsePermissions($permissions['admin'] ?? [], $extensionId);

        return new ExtensionCapabilitySet(
            clientRoutes: (bool) ($routes['client'] ?? false),
            adminRoutes: (bool) ($routes['admin'] ?? false),
            serverPages: $this->parsePages($pages['server'] ?? [], 'server', $extensionId, $adminPermissions),
            adminPages: $this->parsePages($pages['admin'] ?? [], 'admin', $extensionId, $adminPermissions),
            adminPermissions: $adminPermissions,
            migrations: (bool) ($database['migrations'] ?? false),
            tables: $this->parseTables($database['tables'] ?? [], $extensionId),
            hooks: $this->parseHooks($capabilities['hooks'] ?? [], $extensionId),
            queues: $this->parseQueues($capabilities['queues'] ?? []),
            schedule: (bool) ($capabilities['schedule'] ?? false),
            commands: $this->parseCommands($capabilities['commands'] ?? [], $extensionId),
            secrets: $this->parseSecrets($capabilities['secrets'] ?? [], $extensionId),
            settings: $this->parseSettings($capabilities['settings'] ?? [], $extensionId),
        );
    }

    /**
     * @param array<int, PermissionDefinition> $adminPermissions
     *
     * @return array<int, PageDefinition>
     */
    private function parsePages($pages, string $surface, string $extensionId, array $adminPermissions): array
    {
        if ($pages === [] || $pages === null) {
            return [];
        }

        if (!is_array($pages) || !array_is_list($pages)) {
            throw new DisplayException(sprintf('The manifest "capabilities.pages.%s" section must be a list.', $surface));
        }

        $categories = $surface === 'server'
            ? ExtensionCapabilityVocabulary::SERVER_CATEGORIES
            : ExtensionCapabilityVocabulary::ADMIN_CATEGORIES;

        $permissionKeys = array_map(fn (PermissionDefinition $p): string => $p->key, $adminPermissions);
        $definitions = [];
        $seen = [];

        foreach ($pages as $index => $page) {
            $where = sprintf('capabilities.pages.%s[%d]', $surface, $index);

            if (!is_array($page)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($page, [
                'slug', 'labelKey', 'icon', 'category', 'order',
                'requiredServerPermission', 'requiredExtensionPermission',
            ], $where);

            $slug = $this->slug($page['slug'] ?? '', $where . '.slug');
            if (isset($seen[$slug])) {
                throw new DisplayException(sprintf('Duplicate %s page slug "%s".', $surface, $slug));
            }
            $seen[$slug] = true;

            $category = (string) ($page['category'] ?? '');
            if (!in_array($category, $categories, true)) {
                throw new DisplayException(sprintf('%s declares category "%s". Allowed: %s.', $where, $category, implode(', ', $categories)));
            }

            // An admin page gated on a permission the manifest never declared
            // would be unreachable, since the permission would not exist to grant.
            $requiredExtensionPermission = isset($page['requiredExtensionPermission'])
                ? (string) $page['requiredExtensionPermission']
                : null;

            if ($requiredExtensionPermission !== null && !in_array($requiredExtensionPermission, $permissionKeys, true)) {
                throw new DisplayException(sprintf('%s requires the admin permission "%s", which the manifest does not declare.', $where, $requiredExtensionPermission));
            }

            if ($surface === 'server' && $requiredExtensionPermission !== null) {
                throw new DisplayException(sprintf('%s may not use requiredExtensionPermission; server pages gate on core server permissions.', $where));
            }

            if ($surface === 'admin' && isset($page['requiredServerPermission'])) {
                throw new DisplayException(sprintf('%s may not use requiredServerPermission.', $where));
            }

            $definitions[] = new PageDefinition(
                slug: $slug,
                labelKey: $this->labelKey($page['labelKey'] ?? '', $extensionId, $where . '.labelKey'),
                icon: $this->icon($page['icon'] ?? 'puzzle', $where . '.icon'),
                category: $category,
                order: (int) ($page['order'] ?? 100),
                requiredServerPermission: isset($page['requiredServerPermission'])
                    ? (string) $page['requiredServerPermission']
                    : null,
                requiredExtensionPermission: $requiredExtensionPermission,
            );
        }

        return $definitions;
    }

    /**
     * @return array<int, PermissionDefinition>
     */
    private function parsePermissions($permissions, string $extensionId): array
    {
        if ($permissions === [] || $permissions === null) {
            return [];
        }

        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new DisplayException('The manifest "capabilities.permissions.admin" section must be a list.');
        }

        $definitions = [];
        $seen = [];

        foreach ($permissions as $index => $permission) {
            $where = sprintf('capabilities.permissions.admin[%d]', $index);

            if (!is_array($permission)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($permission, ['key', 'labelKey', 'descriptionKey', 'dangerous'], $where);

            $key = $this->slug($permission['key'] ?? '', $where . '.key');
            if (isset($seen[$key])) {
                throw new DisplayException(sprintf('Duplicate admin permission "%s".', $key));
            }
            $seen[$key] = true;

            $definitions[] = new PermissionDefinition(
                key: $key,
                labelKey: $this->labelKey($permission['labelKey'] ?? '', $extensionId, $where . '.labelKey'),
                descriptionKey: isset($permission['descriptionKey'])
                    ? $this->labelKey($permission['descriptionKey'], $extensionId, $where . '.descriptionKey')
                    : null,
                dangerous: (bool) ($permission['dangerous'] ?? false),
            );
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    private function parseTables($tables, string $extensionId): array
    {
        if ($tables === [] || $tables === null) {
            return [];
        }

        if (!is_array($tables) || !array_is_list($tables)) {
            throw new DisplayException('The manifest "capabilities.database.tables" section must be a list.');
        }

        $prefix = sprintf('ext_%s_', $extensionId);

        return array_values(array_map(function ($table) use ($prefix): string {
            $name = (string) $table;

            // Table ownership is what keeps two packages, and a package and
            // core, from claiming the same storage.
            if (!str_starts_with($name, $prefix)) {
                throw new DisplayException(sprintf('The declared table "%s" must start with "%s".', $name, $prefix));
            }

            if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
                throw new DisplayException(sprintf('The declared table name "%s" is invalid.', $name));
            }

            return $name;
        }, $tables));
    }

    /**
     * @return array<int, HookDefinition>
     */
    private function parseHooks($hooks, string $extensionId): array
    {
        if ($hooks === [] || $hooks === null) {
            return [];
        }

        if (!is_array($hooks) || !array_is_list($hooks)) {
            throw new DisplayException('The manifest "capabilities.hooks" section must be a list.');
        }

        $definitions = [];
        $seen = [];

        foreach ($hooks as $index => $hook) {
            $where = sprintf('capabilities.hooks[%d]', $index);

            if (!is_array($hook)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($hook, ['event', 'handler', 'mode', 'timeoutMs'], $where);

            $event = (string) ($hook['event'] ?? '');
            if (!in_array($event, ExtensionCapabilityVocabulary::HOOK_EVENTS, true)) {
                throw new DisplayException(sprintf('%s subscribes to "%s", which is not a documented extension event. Allowed: %s.', $where, $event, implode(', ', ExtensionCapabilityVocabulary::HOOK_EVENTS)));
            }

            $mode = (string) ($hook['mode'] ?? '');
            if (in_array($mode, ExtensionCapabilityVocabulary::REJECTED_HOOK_MODES, true)) {
                throw new DisplayException(sprintf('%s requests the "%s" delivery mode, which this panel does not offer: a handler that can abort a core operation has no bounded failure. Use synchronous_best_effort or queued_at_least_once.', $where, $mode));
            }

            if (!in_array($mode, ExtensionCapabilityVocabulary::HOOK_MODES, true)) {
                throw new DisplayException(sprintf('%s declares delivery mode "%s". Allowed: %s.', $where, $mode, implode(', ', ExtensionCapabilityVocabulary::HOOK_MODES)));
            }

            $handler = (string) ($hook['handler'] ?? '');
            if (!preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/', $handler)) {
                throw new DisplayException(sprintf('%s declares an invalid handler class name "%s". Use a single StudlyCase class name inside the package Hooks namespace.', $where, $handler));
            }

            $signature = $event . '|' . $handler;
            if (isset($seen[$signature])) {
                throw new DisplayException(sprintf('Duplicate hook %s for event "%s".', $handler, $event));
            }
            $seen[$signature] = true;

            $timeout = (int) ($hook['timeoutMs'] ?? 3000);
            if ($timeout < 100 || $timeout > 15000) {
                throw new DisplayException(sprintf('%s declares timeoutMs %d; it must be between 100 and 15000.', $where, $timeout));
            }

            $definitions[] = new HookDefinition($event, $handler, $mode, $timeout);
        }

        return $definitions;
    }

    /**
     * @return array<int, QueueDefinition>
     */
    private function parseQueues($queues): array
    {
        if ($queues === [] || $queues === null) {
            return [];
        }

        if (!is_array($queues) || !array_is_list($queues)) {
            throw new DisplayException('The manifest "capabilities.queues" section must be a list.');
        }

        $definitions = [];
        $seen = [];

        foreach ($queues as $index => $queue) {
            $where = sprintf('capabilities.queues[%d]', $index);

            if (!is_array($queue)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($queue, [
                'name', 'maxAttempts', 'timeoutSeconds', 'backoffSeconds',
                'rateLimit', 'maxConcurrent', 'maxOutstanding', 'uniqueForSeconds',
            ], $where);

            $name = $this->slug($queue['name'] ?? '', $where . '.name');
            if (isset($seen[$name])) {
                throw new DisplayException(sprintf('Duplicate queue name "%s".', $name));
            }
            $seen[$name] = true;

            $rateLimit = isset($queue['rateLimit']) ? (string) $queue['rateLimit'] : null;
            if ($rateLimit !== null && !preg_match('#^\d+/(second|minute|hour)$#', $rateLimit)) {
                throw new DisplayException(sprintf('%s declares rateLimit "%s"; use the form "60/minute".', $where, $rateLimit));
            }

            $backoff = $queue['backoffSeconds'] ?? [10, 60, 300];
            if (!is_array($backoff) || !array_is_list($backoff) || $backoff === []) {
                throw new DisplayException(sprintf('%s.backoffSeconds must be a non-empty list of seconds.', $where));
            }

            $definitions[] = new QueueDefinition(
                name: $name,
                maxAttempts: $this->boundedInt($queue['maxAttempts'] ?? 3, 1, 25, $where . '.maxAttempts'),
                timeoutSeconds: $this->boundedInt($queue['timeoutSeconds'] ?? 60, 1, 3600, $where . '.timeoutSeconds'),
                backoffSeconds: array_map(fn ($seconds): int => $this->boundedInt($seconds, 0, 86400, $where . '.backoffSeconds'), $backoff),
                rateLimit: $rateLimit,
                maxConcurrent: isset($queue['maxConcurrent']) ? $this->boundedInt($queue['maxConcurrent'], 1, 100, $where . '.maxConcurrent') : null,
                maxOutstanding: isset($queue['maxOutstanding']) ? $this->boundedInt($queue['maxOutstanding'], 1, 100000, $where . '.maxOutstanding') : null,
                uniqueForSeconds: isset($queue['uniqueForSeconds']) ? $this->boundedInt($queue['uniqueForSeconds'], 1, 86400, $where . '.uniqueForSeconds') : null,
            );
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    private function parseCommands($commands, string $extensionId): array
    {
        if ($commands === [] || $commands === null) {
            return [];
        }

        if (!is_array($commands) || !array_is_list($commands)) {
            throw new DisplayException('The manifest "capabilities.commands" section must be a list.');
        }

        // Commands share the global artisan namespace, so the prefix is what
        // stops a package shadowing a core command such as p:extensions:install.
        $prefix = sprintf('p:ext:%s:', str_replace('_', '-', $extensionId));

        return array_values(array_map(function ($command) use ($prefix): string {
            $signature = (string) $command;

            if (!str_starts_with($signature, $prefix)) {
                throw new DisplayException(sprintf('The command "%s" must start with "%s".', $signature, $prefix));
            }

            if (!preg_match('/^[a-z0-9:_-]{1,96}$/', $signature)) {
                throw new DisplayException(sprintf('The command name "%s" is invalid.', $signature));
            }

            return $signature;
        }, $commands));
    }

    /**
     * @return array<int, SecretDefinition>
     */
    private function parseSecrets($secrets, string $extensionId): array
    {
        if ($secrets === [] || $secrets === null) {
            return [];
        }

        if (!is_array($secrets) || !array_is_list($secrets)) {
            throw new DisplayException('The manifest "capabilities.secrets" section must be a list.');
        }

        $definitions = [];
        $seen = [];

        foreach ($secrets as $index => $secret) {
            $where = sprintf('capabilities.secrets[%d]', $index);

            if (!is_array($secret)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($secret, ['key', 'labelKey', 'helpKey', 'rotatable', 'default'], $where);

            // A secret with a default would ship the credential in the archive
            // and the registry, where it is neither secret nor rotatable.
            if (array_key_exists('default', $secret)) {
                throw new DisplayException(sprintf('%s may not declare a default. Secrets are entered by an administrator, never shipped in a package.', $where));
            }

            $key = $this->settingKey($secret['key'] ?? '', $where . '.key');
            if (isset($seen[$key])) {
                throw new DisplayException(sprintf('Duplicate secret "%s".', $key));
            }
            $seen[$key] = true;

            $definitions[] = new SecretDefinition(
                key: $key,
                labelKey: $this->labelKey($secret['labelKey'] ?? '', $extensionId, $where . '.labelKey'),
                helpKey: isset($secret['helpKey']) ? $this->labelKey($secret['helpKey'], $extensionId, $where . '.helpKey') : null,
                rotatable: (bool) ($secret['rotatable'] ?? true),
            );
        }

        return $definitions;
    }

    /**
     * @return array<int, SettingDefinition>
     */
    private function parseSettings($settings, string $extensionId): array
    {
        if ($settings === [] || $settings === null) {
            return [];
        }

        if (!is_array($settings)) {
            throw new DisplayException('The manifest "capabilities.settings" section must be an object.');
        }

        $this->assertKnownKeys($settings, ['fields'], 'capabilities.settings');

        $fields = $settings['fields'] ?? [];
        if ($fields === []) {
            return [];
        }

        if (!is_array($fields) || !array_is_list($fields)) {
            throw new DisplayException('The manifest "capabilities.settings.fields" section must be a list.');
        }

        $definitions = [];
        $seen = [];

        foreach ($fields as $index => $field) {
            $where = sprintf('capabilities.settings.fields[%d]', $index);

            if (!is_array($field)) {
                throw new DisplayException(sprintf('%s must be an object.', $where));
            }

            $this->assertKnownKeys($field, [
                'key', 'type', 'labelKey', 'helpKey', 'required', 'default',
                'minLength', 'maxLength', 'pattern', 'enum', 'min', 'max',
                'urlHosts', 'visibility', 'requiresRebuild',
            ], $where);

            $key = $this->settingKey($field['key'] ?? '', $where . '.key');
            if (isset($seen[$key])) {
                throw new DisplayException(sprintf('Duplicate setting key "%s".', $key));
            }
            $seen[$key] = true;

            $type = (string) ($field['type'] ?? '');
            if ($type === 'password') {
                throw new DisplayException(sprintf('%s uses the "password" type, which this panel does not offer: settings are stored as readable JSON. Declare it under capabilities.secrets instead.', $where));
            }
            if (!in_array($type, ExtensionCapabilityVocabulary::SETTING_TYPES, true)) {
                throw new DisplayException(sprintf('%s declares type "%s". Allowed: %s.', $where, $type, implode(', ', ExtensionCapabilityVocabulary::SETTING_TYPES)));
            }

            $visibility = (string) ($field['visibility'] ?? 'admin');
            if (!in_array($visibility, ExtensionCapabilityVocabulary::SETTING_VISIBILITIES, true)) {
                throw new DisplayException(sprintf('%s declares visibility "%s". Allowed: %s.', $where, $visibility, implode(', ', ExtensionCapabilityVocabulary::SETTING_VISIBILITIES)));
            }

            $enum = $field['enum'] ?? null;
            if ($enum !== null && (!is_array($enum) || !array_is_list($enum) || $enum === [])) {
                throw new DisplayException(sprintf('%s.enum must be a non-empty list.', $where));
            }
            if ($type === 'select' && $enum === null) {
                throw new DisplayException(sprintf('%s is a select field and must declare an enum.', $where));
            }

            $definitions[] = new SettingDefinition(
                key: $key,
                type: $type,
                labelKey: $this->labelKey($field['labelKey'] ?? '', $extensionId, $where . '.labelKey'),
                helpKey: isset($field['helpKey']) ? $this->labelKey($field['helpKey'], $extensionId, $where . '.helpKey') : null,
                required: (bool) ($field['required'] ?? false),
                default: $field['default'] ?? null,
                minLength: isset($field['minLength']) ? $this->boundedInt($field['minLength'], 0, 65535, $where . '.minLength') : null,
                maxLength: isset($field['maxLength']) ? $this->boundedInt($field['maxLength'], 1, 65535, $where . '.maxLength') : null,
                pattern: isset($field['pattern']) ? $this->pattern((string) $field['pattern'], $where . '.pattern') : null,
                enum: $enum === null ? null : array_map('strval', $enum),
                min: isset($field['min']) && is_numeric($field['min']) ? $field['min'] + 0 : null,
                max: isset($field['max']) && is_numeric($field['max']) ? $field['max'] + 0 : null,
                urlHosts: isset($field['urlHosts']) && is_array($field['urlHosts'])
                    ? array_values(array_map('strval', $field['urlHosts']))
                    : null,
                visibility: $visibility,
                requiresRebuild: (bool) ($field['requiresRebuild'] ?? false),
            );
        }

        return $definitions;
    }

    /**
     * A settings pattern is applied to administrator input on every save, so it
     * is bounded and stripped of the constructs that make catastrophic
     * backtracking possible.
     */
    private function pattern(string $pattern, string $where): string
    {
        if (strlen($pattern) > 200) {
            throw new DisplayException(sprintf('%s is longer than 200 characters.', $where));
        }

        foreach (['(?R)', '(?0)', '(?(', '(?P>', '\\1', '\\2', '\\3'] as $construct) {
            if (str_contains($pattern, $construct)) {
                throw new DisplayException(sprintf('%s uses a recursive or back-referencing construct, which is not permitted.', $where));
            }
        }

        if (@preg_match('/' . str_replace('/', '\\/', $pattern) . '/', '') === false) {
            throw new DisplayException(sprintf('%s is not a valid regular expression.', $where));
        }

        return $pattern;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDefaults($defaults, ExtensionCapabilitySet $capabilities): array
    {
        if ($defaults === [] || $defaults === null) {
            return ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []];
        }

        if (!is_array($defaults)) {
            throw new DisplayException('The manifest "extension.defaults" section must be an object.');
        }

        $this->assertKnownKeys($defaults, ['enabled', 'allowedNests', 'allowedEggs', 'settings'], 'extension.defaults');

        $settings = $defaults['settings'] ?? [];
        if (!is_array($settings)) {
            throw new DisplayException('The manifest "extension.defaults.settings" section must be an object.');
        }

        // Defaults are configuration like any other, so they answer to the same
        // schema. A default for an undeclared key would be unreachable and
        // unvalidatable once written.
        foreach (array_keys($settings) as $key) {
            if ($capabilities->setting((string) $key) === null) {
                throw new DisplayException(sprintf('extension.defaults.settings declares "%s", which is not a declared setting field.', $key));
            }
        }

        foreach ($capabilities->settings as $setting) {
            if ($setting->isSecret() && !$capabilities->declaresSecret($setting->key)) {
                throw new DisplayException(sprintf('The setting "%s" is marked secret but is not declared under capabilities.secrets.', $setting->key));
            }
        }

        return [
            'enabled' => (bool) ($defaults['enabled'] ?? false),
            'allowedNests' => array_values(array_filter((array) ($defaults['allowedNests'] ?? []), 'is_int')),
            'allowedEggs' => array_values(array_filter((array) ($defaults['allowedEggs'] ?? []), 'is_int')),
            'settings' => $settings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRequirements($requirements): array
    {
        if ($requirements === [] || $requirements === null) {
            return ['phpExtensions' => [], 'panelServices' => [], 'extensions' => []];
        }

        if (!is_array($requirements)) {
            throw new DisplayException('The manifest "requirements" section must be an object.');
        }

        $this->assertKnownKeys($requirements, ['phpExtensions', 'panelServices', 'extensions'], 'requirements');

        // Cross-extension dependencies need a resolution graph, cycle detection
        // and coordinated updates, none of which exist yet. Packages coordinate
        // through stable ids and events instead.
        $dependencies = (array) ($requirements['extensions'] ?? []);
        if ($dependencies !== []) {
            throw new DisplayException('Extension-to-extension dependencies are not supported. Use stable ids and documented events instead.');
        }

        $services = array_values(array_map('strval', (array) ($requirements['panelServices'] ?? [])));
        foreach ($services as $service) {
            if (!in_array($service, ExtensionCapabilityVocabulary::PANEL_SERVICES, true)) {
                throw new DisplayException(sprintf('requirements.panelServices names "%s". Allowed: %s.', $service, implode(', ', ExtensionCapabilityVocabulary::PANEL_SERVICES)));
            }
        }

        return [
            'phpExtensions' => array_values(array_map('strval', (array) ($requirements['phpExtensions'] ?? []))),
            'panelServices' => $services,
            'extensions' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseCompatibility($versions): array
    {
        if (!is_array($versions) || !array_is_list($versions) || $versions === []) {
            throw new DisplayException('The manifest must declare at least one entry in "compatiblePanelVersions".');
        }

        return array_values(array_map('strval', $versions));
    }

    /**
     * @return array<int, array{path: string, sha256: string}>
     */
    private function parseFiles($files): array
    {
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw new DisplayException('The manifest must declare the files the package ships.');
        }

        $parsed = [];
        $seen = [];

        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                throw new DisplayException(sprintf('files[%d] must be an object.', $index));
            }

            $path = str_replace('\\', '/', trim((string) ($file['path'] ?? '')));
            $checksum = strtolower(trim((string) ($file['sha256'] ?? '')));

            if ($path === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                throw new DisplayException(sprintf('files[%d] must declare a path and a sha256 checksum.', $index));
            }

            if (isset($seen[$path])) {
                throw new DisplayException(sprintf('The manifest declares "%s" more than once.', $path));
            }
            $seen[$path] = true;

            $parsed[] = ['path' => $path, 'sha256' => $checksum];
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseIntegrity($integrity): ?array
    {
        if ($integrity === null) {
            return null;
        }

        if (!is_array($integrity)) {
            throw new DisplayException('The manifest "integrity" section must be an object.');
        }

        $this->assertKnownKeys($integrity, ['archiveSha256', 'signatureAlgorithm', 'keyId', 'signature'], 'integrity');

        $algorithm = (string) ($integrity['signatureAlgorithm'] ?? 'ed25519');
        if ($algorithm !== 'ed25519') {
            throw new DisplayException(sprintf('The manifest declares signature algorithm "%s"; only ed25519 is supported.', $algorithm));
        }

        return [
            'archiveSha256' => strtolower((string) ($integrity['archiveSha256'] ?? '')),
            'signatureAlgorithm' => $algorithm,
            'keyId' => isset($integrity['keyId']) ? (string) $integrity['keyId'] : null,
            'signature' => isset($integrity['signature']) ? (string) $integrity['signature'] : null,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $section
     * @param array<int, string> $allowed
     */
    private function assertKnownKeys(array $section, array $allowed, string $where): void
    {
        $unknown = array_diff(array_keys($section), $allowed);

        if ($unknown !== []) {
            throw new DisplayException(sprintf('The manifest section "%s" declares unknown key(s): %s. Allowed: %s.', $where, implode(', ', array_map('strval', $unknown)), implode(', ', $allowed)));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function section(array $manifest, string $key): array
    {
        $section = $manifest[$key] ?? null;

        if (!is_array($section)) {
            throw new DisplayException(sprintf('The manifest is missing the required "%s" section.', $key));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function string(array $section, string $key, string $where): string
    {
        $value = trim((string) ($section[$key] ?? ''));

        if ($value === '') {
            throw new DisplayException(sprintf('The manifest is missing "%s".', $where));
        }

        return $value;
    }

    private function slug(mixed $value, string $where): string
    {
        $slug = (string) $value;

        if (!preg_match(ExtensionCapabilityVocabulary::SLUG_PATTERN, $slug)) {
            throw new DisplayException(sprintf('%s must be a lowercase slug of up to 32 characters (letters, digits, hyphens). Got "%s".', $where, $slug));
        }

        return $slug;
    }

    private function settingKey(mixed $value, string $where): string
    {
        $key = (string) $value;

        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            throw new DisplayException(sprintf('%s must be a lowercase snake_case key. Got "%s".', $where, $key));
        }

        return $key;
    }

    private function icon(mixed $value, string $where): string
    {
        $icon = Str::lower((string) $value);

        if (!in_array($icon, ExtensionCapabilityVocabulary::ICONS, true)) {
            throw new DisplayException(sprintf('%s names icon "%s", which is not in the approved icon set.', $where, $icon));
        }

        return $icon;
    }

    /**
     * Translation keys must live under the package's own namespace, so one
     * package cannot override another's — or the panel's — strings.
     */
    private function labelKey(mixed $value, string $extensionId, string $where): string
    {
        $key = (string) $value;
        $prefix = sprintf('ext.%s.', $extensionId);

        if (!str_starts_with($key, $prefix)) {
            throw new DisplayException(sprintf('%s must start with "%s". Got "%s".', $where, $prefix, $key));
        }

        if (!preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $key)) {
            throw new DisplayException(sprintf('%s is not a valid translation key.', $where));
        }

        return $key;
    }

    private function boundedInt(mixed $value, int $min, int $max, string $where): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new DisplayException(sprintf('%s must be an integer.', $where));
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new DisplayException(sprintf('%s must be between %d and %d. Got %d.', $where, $min, $max, $int));
        }

        return $int;
    }
}
