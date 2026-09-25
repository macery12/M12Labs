<?php

namespace Everest\Services\Extensions\Manifest;

use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\StreamDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagDefinition;
use Everest\Services\Extensions\Manifest\Definitions\FrontendSlotDefinition;

/**
 * Every executable or privileged surface a package declares.
 *
 * This is the object the runtime consults — route loaders, the hook dispatcher,
 * the queue registry, the permission registry and the frontend page generator
 * all read it rather than poking at raw manifest arrays. A capability absent
 * here means denied: nothing is inferred from files on disk.
 */
final readonly class ExtensionCapabilitySet implements \JsonSerializable
{
    /**
     * @param array<int, PageDefinition> $serverPages
     * @param array<int, PageDefinition> $adminPages
     * @param array<int, PermissionDefinition> $adminPermissions
     * @param array<int, string> $tables
     * @param array<int, HookDefinition> $hooks
     * @param array<int, QueueDefinition> $queues
     * @param array<int, string> $commands
     * @param array<int, SecretDefinition> $secrets
     * @param array<int, SettingDefinition> $settings
     * @param array<int, string> $privileged
     * @param array<int, string> $bindings
     * @param array<int, StreamDefinition> $streams
     * @param array<int, FrontendSlotDefinition> $slots
     * @param array<int, PackageFlagDefinition> $flags
     */
    public function __construct(
        public bool $clientRoutes = false,
        public bool $adminRoutes = false,
        public array $serverPages = [],
        public array $adminPages = [],
        public array $adminPermissions = [],
        public bool $migrations = false,
        public array $tables = [],
        public array $hooks = [],
        public array $queues = [],
        public bool $schedule = false,
        public array $commands = [],
        public array $secrets = [],
        public array $settings = [],
        public array $privileged = [],
        public array $bindings = [],
        public array $streams = [],
        public array $slots = [],
        public array $flags = [],
    ) {
    }

    /** The declared limits for one stream kind, or null when undeclared. */
    public function streamNamed(string $name): ?StreamDefinition
    {
        foreach ($this->streams as $stream) {
            if ($stream->name === $name) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * Whether core has been asked for one of its privileged services, and an
     * administrator approved it at install.
     *
     * @see ExtensionCapabilityVocabulary::PRIVILEGED
     */
    public function grantsPrivilege(string $name): bool
    {
        return in_array($name, $this->privileged, true);
    }

    public function hasPages(): bool
    {
        return $this->serverPages !== [] || $this->adminPages !== [];
    }

    public function hasServerPages(): bool
    {
        return $this->serverPages !== [];
    }

    public function hasAdminPages(): bool
    {
        return $this->adminPages !== [];
    }

    public function queue(string $name): ?QueueDefinition
    {
        foreach ($this->queues as $queue) {
            if ($queue->name === $name) {
                return $queue;
            }
        }

        return null;
    }

    /** @return array<int, HookDefinition> */
    public function hooksFor(string $event): array
    {
        return array_values(array_filter($this->hooks, fn (HookDefinition $hook): bool => $hook->event === $event));
    }

    public function setting(string $key): ?SettingDefinition
    {
        foreach ($this->settings as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        return null;
    }

    public function declaresSecret(string $key): bool
    {
        foreach ($this->secrets as $secret) {
            if ($secret->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stable digest of the declared capabilities.
     *
     * Persisted alongside the package so the runtime can detect a stored
     * capability projection that no longer matches its manifest, and so an
     * update's capability diff can be approved by hash rather than by
     * re-serializing the whole set through the API.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $projection = [
            'routes' => ['client' => $this->clientRoutes, 'admin' => $this->adminRoutes],
            'pages' => [
                'server' => array_map(fn (PageDefinition $page): array => $page->jsonSerialize(), $this->serverPages),
                'admin' => array_map(fn (PageDefinition $page): array => $page->jsonSerialize(), $this->adminPages),
            ],
            'permissions' => [
                'admin' => array_map(fn (PermissionDefinition $p): array => $p->jsonSerialize(), $this->adminPermissions),
            ],
            'database' => ['migrations' => $this->migrations, 'tables' => $this->tables],
            'hooks' => array_map(fn (HookDefinition $hook): array => $hook->jsonSerialize(), $this->hooks),
            'queues' => array_map(fn (QueueDefinition $queue): array => $queue->jsonSerialize(), $this->queues),
            'schedule' => $this->schedule,
            'commands' => $this->commands,
            'secrets' => array_map(fn (SecretDefinition $secret): array => $secret->jsonSerialize(), $this->secrets),
            'settings' => ['fields' => array_map(fn (SettingDefinition $s): array => $s->jsonSerialize(), $this->settings)],
        ];

        // Only when something was asked for. This projection is what
        // `capability_hash` hashes, and `ExtensionRuntimePlanService` re-hashes
        // the *stored* copy on every plan build to catch a projection edited in
        // the database. An unconditional key would therefore change the hash of
        // every package installed before this existed, and take the lot inert.
        if ($this->privileged !== []) {
            $projection['privileged'] = $this->privileged;
        }
        if ($this->bindings !== []) {
            $projection['bindings'] = $this->bindings;
        }
        if ($this->streams !== []) {
            $projection['streams'] = array_map(fn (StreamDefinition $s): array => $s->jsonSerialize(), $this->streams);
        }
        if ($this->slots !== []) {
            $projection['slots'] = array_map(fn (FrontendSlotDefinition $s): array => $s->jsonSerialize(), $this->slots);
        }
        if ($this->flags !== []) {
            $projection['flags'] = array_map(fn (PackageFlagDefinition $f): array => $f->jsonSerialize(), $this->flags);
        }

        return $projection;
    }

    /**
     * Short, human-readable summary for the install approval dialog and the
     * admin catalog card.
     *
     * @return array<string, int|bool>
     */
    public function summary(): array
    {
        return [
            'pages' => count($this->serverPages) + count($this->adminPages),
            'adminPermissions' => count($this->adminPermissions),
            'hooks' => count($this->hooks),
            'queues' => count($this->queues),
            'secrets' => count($this->secrets),
            'commands' => count($this->commands),
            'migrations' => $this->migrations,
            'schedule' => $this->schedule,
            'clientRoutes' => $this->clientRoutes,
            'adminRoutes' => $this->adminRoutes,
            'privileged' => count($this->privileged),
            'bindings' => count($this->bindings),
            'streams' => count($this->streams),
            'slots' => count($this->slots),
            'flags' => count($this->flags),
        ];
    }
}
