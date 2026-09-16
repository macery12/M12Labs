<?php

namespace Everest\Services\Extensions\Manifest;

/**
 * What changes about a package's privileges between two versions.
 *
 * An update must never silently widen what a package can do. This computes the
 * difference so the installer can show it and require a fresh administrator
 * approval before an update that asks for more than the installed version had.
 */
final readonly class ExtensionCapabilityDiff implements \JsonSerializable
{
    /**
     * @param array<int, string> $added
     * @param array<int, string> $removed
     * @param array<int, string> $escalations
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $escalations,
        public string $hash,
    ) {
    }

    /**
     * True when the new version asks for a privilege the old one did not hold.
     * Broadening is what needs consent; narrowing does not.
     */
    public function isEscalation(): bool
    {
        return $this->escalations !== [];
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [];
    }

    public static function between(?ExtensionCapabilitySet $old, ExtensionCapabilitySet $new): self
    {
        $before = $old === null ? [] : self::flatten($old);
        $after = self::flatten($new);

        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));

        // Only surfaces that grant new reach need consent. Pages are executable
        // frontend entry points mounted into user/admin navigation, so adding
        // one is a privilege increase just like adding a route. A settings
        // field is declarative metadata and remains review-only. A privileged
        // service is the plainest escalation of the lot — it is core handing
        // authority back rather than mounting something the package wrote. A
        // stream is here for a different reason than the rest: it is the one
        // surface whose cost is a held PHP worker rather than a request, so an
        // operator needs to see it arrive even though the package could already
        // serve the same data by polling.
        $escalations = array_values(array_filter(
            $added,
            fn (string $capability): bool => (bool) preg_match(
                '/^(routes|page|permission|hook|queue|secret|command|migrations|schedule|table|privileged|stream)\b/',
                $capability
            )
        ));

        return new self($added, $removed, $escalations, $new->hash());
    }

    /**
     * Flatten a capability set into comparable, human-readable statements. The
     * strings are what the approval dialog shows, so they read as privileges
     * rather than as manifest paths.
     *
     * @return array<string, true>
     */
    private static function flatten(ExtensionCapabilitySet $set): array
    {
        $flat = [];

        if ($set->clientRoutes) {
            $flat['routes.client'] = true;
        }
        if ($set->adminRoutes) {
            $flat['routes.admin'] = true;
        }
        if ($set->migrations) {
            $flat['migrations'] = true;
        }
        if ($set->schedule) {
            $flat['schedule'] = true;
        }

        foreach ($set->tables as $table) {
            $flat['table:' . $table] = true;
        }
        foreach ($set->adminPermissions as $permission) {
            $flat['permission:' . $permission->key . ($permission->dangerous ? ' (destructive)' : '')] = true;
        }
        foreach ($set->hooks as $hook) {
            $flat['hook:' . $hook->event . ' -> ' . $hook->handler . ' (' . $hook->mode . ')'] = true;
        }
        // For the same reason streams carry theirs below: the timeout is how
        // long one job holds a worker, and `longRunning` is which lane holds
        // it. A group moved onto the long lane, or widened from a minute to an
        // hour, is a different ask and has to read as one.
        foreach ($set->queues as $queue) {
            $flat[sprintf(
                'queue:%s (%ds%s)',
                $queue->name,
                $queue->timeoutSeconds,
                $queue->longRunning ? ', long-running' : '',
            )] = true;
        }
        foreach ($set->secrets as $secret) {
            $flat['secret:' . $secret->key] = true;
        }
        foreach ($set->commands as $command) {
            $flat['command:' . $command] = true;
        }
        foreach ($set->serverPages as $page) {
            $flat['page.server:' . $page->slug] = true;
        }
        foreach ($set->adminPages as $page) {
            $flat['page.admin:' . $page->slug] = true;
        }
        foreach ($set->settings as $setting) {
            $flat['setting:' . $setting->key] = true;
        }
        foreach ($set->privileged as $service) {
            $flat['privileged:' . $service] = true;
        }
        // The limits are part of the statement: a stream widened from thirty
        // seconds to fifteen minutes is a different ask, and reads as one.
        foreach ($set->streams as $stream) {
            $flat[sprintf('stream:%s (%ds, %d per user)', $stream->name, $stream->maxSeconds, $stream->maxConcurrentPerUser)] = true;
        }
        // Informational, like a settings field: making one of a package's own
        // classes shared reaches nothing the package could not already reach,
        // and is inert unless something that runs resolves it.
        foreach ($set->bindings as $binding) {
            $flat['binding:' . $binding] = true;
        }

        return $flat;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'added' => $this->added,
            'removed' => $this->removed,
            'escalations' => $this->escalations,
            'isEscalation' => $this->isEscalation(),
            'hash' => $this->hash,
        ];
    }
}
