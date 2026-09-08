<?php

namespace Everest\Services\Extensions;

use Everest\Models\AdminRole;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPermission;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * The bridge between a package's declared admin permissions and core's
 * capability catalog.
 *
 * AdminCapabilityRegistry::all() flattens AdminRole::permissions() as
 * "<namespace>.<key>", so contributing the namespace ext.<id>.admin with the
 * keys a package declared produces exactly the identifiers this registry
 * stores. Everything downstream — role validation, the authorizer, the API
 * access profile, the frontend permission matrix — then works unchanged.
 *
 * Three rules give the guarantees:
 *
 *  - Contributed permissions are *assignable*, never *granted*. Nothing here
 *    writes into admin_roles.permissions; the Owner keeps implicit access
 *    through AdminAuthorizer's isOwner() short-circuit.
 *  - The catalog lists permissions from every *installed* extension, not every
 *    enabled one. A disabled extension's permission stays a valid identifier,
 *    so saving an unrelated role does not quietly strip the grant. Enforcement
 *    happens instead at authorization time, where a suspended permission is
 *    denied.
 *  - A permission introduced by an update lands unapproved, which keeps it out
 *    of the catalog entirely until the capability diff is approved. An update
 *    therefore cannot widen what a role can be given without an administrator
 *    seeing the request.
 */
class ExtensionPermissionRegistry
{
    /** Namespace suffix; the full group key is ext.<id>.admin. */
    private const NAMESPACE_SUFFIX = '.admin';

    /**
     * Per-process memo of the contributed catalog. AdminRole::permissions() is
     * called several times per request (validation, the authorizer, the API
     * profile), and none of them may pay for a query each.
     *
     * @var array<string, array{description: string, keys: array<string, string>, labelKeys: array<string, string>, descriptionKeys: array<string, string>, extensionId: string}>|null
     */
    private static ?array $groups = null;

    /** @var array<int, string>|null */
    private static ?array $suspended = null;

    /**
     * The capability groups to merge into AdminRole::permissions().
     *
     * @return array<string, array{description: string, keys: array<string, string>, labelKeys: array<string, string>, descriptionKeys: array<string, string>, extensionId: string}>
     */
    public function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        try {
            $names = ExtensionPackage::query()
                ->pluck('name', 'extension_id')
                ->all();

            $groups = [];

            $permissions = ExtensionPermission::query()
                ->whereNotNull('approved_at')
                ->orderBy('extension_id')
                ->orderBy('action')
                ->get();

            foreach ($permissions as $permission) {
                $namespace = self::namespaceFor($permission->extension_id);
                $groups[$namespace] ??= [
                    'description' => sprintf(
                        'Permissions contributed by the %s extension.',
                        $names[$permission->extension_id] ?? $permission->extension_id
                    ),
                    'keys' => [],
                    'labelKeys' => [],
                    'descriptionKeys' => [],
                    'extensionId' => $permission->extension_id,
                ];

                // The human text lives in the extension's own translation
                // catalog, which core cannot resolve server-side. The key
                // travels alongside so the frontend can look it up, and the
                // identifier is the fallback rather than an empty cell.
                $groups[$namespace]['keys'][$permission->action] = $permission->identifier;
                $groups[$namespace]['labelKeys'][$permission->action] = $permission->label_key;
                if ($permission->description_key !== null) {
                    $groups[$namespace]['descriptionKeys'][$permission->action] = $permission->description_key;
                }
            }

            return self::$groups = $groups;
        } catch (\Throwable) {
            // The catalog is read during migrations and on a fresh install, at
            // which point the table does not exist. Failing to an empty
            // contribution keeps core's own permissions working, and the
            // failure is not memoized so a later call in the same process
            // resolves the real set.
            return [];
        }
    }

    /**
     * Identifiers that exist but must not authorize anything, because the
     * extension contributing them is currently disabled or uninstalled-pending.
     *
     * @return array<int, string>
     */
    public function suspendedIdentifiers(): array
    {
        if (self::$suspended !== null) {
            return self::$suspended;
        }

        try {
            return self::$suspended = ExtensionPermission::query()
                ->whereNotNull('suspended_at')
                ->pluck('identifier')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Reconcile the stored rows with what a package's manifest declares.
     *
     * Rows the manifest no longer declares are removed from every role in the
     * same transaction as the delete, so a role can never hold an identifier
     * with nothing behind it.
     *
     * $approved says whether the caller established the administrator's consent
     * to this capability set. Install and update pass true because neither can
     * reach persistence without the capability diff being approved first; any
     * other caller (a repair, a backfill) passes false, which leaves new rows
     * out of the catalog and therefore unassignable until somebody approves
     * them explicitly.
     */
    public function sync(
        string $extensionId,
        ExtensionCapabilitySet $capabilities,
        bool $approved = false,
        ?int $approvedBy = null,
    ): void {
        DB::transaction(function () use ($extensionId, $capabilities, $approved, $approvedBy): void {
            $declared = [];

            foreach ($capabilities->adminPermissions as $definition) {
                $declared[$definition->key] = $definition;
            }

            $existing = ExtensionPermission::query()
                ->where('extension_id', $extensionId)
                ->get()
                ->keyBy('action');

            // A permission introduced while the extension is disabled must not
            // start out authorizing anything. Install is the common case: the
            // package is persisted before its config row exists, which reads
            // as disabled and is exactly right.
            $enabled = (bool) ExtensionConfig::query()
                ->where('extension_id', $extensionId)
                ->value('enabled');

            foreach ($declared as $action => $definition) {
                /** @var PermissionDefinition $definition */
                $row = $existing->get($action);

                if ($row instanceof ExtensionPermission) {
                    $row->forceFill([
                        'label_key' => $definition->labelKey,
                        'description_key' => $definition->descriptionKey,
                        'dangerous' => $definition->dangerous,
                    ])->save();

                    continue;
                }

                ExtensionPermission::query()->create([
                    'extension_id' => $extensionId,
                    'action' => $action,
                    'identifier' => $definition->identifier($extensionId),
                    'label_key' => $definition->labelKey,
                    'description_key' => $definition->descriptionKey,
                    'dangerous' => $definition->dangerous,
                    'approved_at' => $approved ? now() : null,
                    'approved_by' => $approved ? $approvedBy : null,
                    'suspended_at' => $enabled ? null : now(),
                ]);
            }

            // Rows the new version stopped declaring. Selected by rejecting
            // from the loaded set rather than with Collection::only(), which on
            // an Eloquent collection keys by primary key and would silently
            // match nothing here.
            $removed = $existing->reject(
                fn (ExtensionPermission $row): bool => isset($declared[$row->action])
            );

            if ($removed->isNotEmpty()) {
                $this->detach($removed->pluck('identifier')->all());

                ExtensionPermission::query()->whereIn('id', $removed->modelKeys())->delete();
            }
        });

        self::flush();
    }

    /**
     * Record that an administrator approved this extension's declared
     * permissions. Called once consent for the capability set is established.
     */
    public function approve(string $extensionId, ?int $approvedBy = null): void
    {
        ExtensionPermission::query()
            ->where('extension_id', $extensionId)
            ->whereNull('approved_at')
            ->update(['approved_at' => now(), 'approved_by' => $approvedBy, 'updated_at' => now()]);

        self::flush();
    }

    /** Deny this extension's permissions without forgetting who holds them. */
    public function suspend(string $extensionId): void
    {
        ExtensionPermission::query()
            ->where('extension_id', $extensionId)
            ->whereNull('suspended_at')
            ->update(['suspended_at' => now(), 'updated_at' => now()]);

        self::flush();
    }

    public function resume(string $extensionId): void
    {
        ExtensionPermission::query()
            ->where('extension_id', $extensionId)
            ->whereNotNull('suspended_at')
            ->update(['suspended_at' => null, 'updated_at' => now()]);

        self::flush();
    }

    /**
     * Remove an extension's permissions entirely and strip them from every role
     * that holds them, in one transaction. Called from uninstall.
     */
    public function purge(string $extensionId): void
    {
        DB::transaction(function () use ($extensionId): void {
            $identifiers = ExtensionPermission::query()
                ->where('extension_id', $extensionId)
                ->pluck('identifier')
                ->all();

            if ($identifiers === []) {
                return;
            }

            $this->detach($identifiers);

            ExtensionPermission::query()->where('extension_id', $extensionId)->delete();
        });

        self::flush();
    }

    /**
     * How many role assignments a purge would remove. Shown in the uninstall
     * confirmation, because losing a grant is not recoverable by reinstalling.
     */
    public function assignmentCount(string $extensionId): int
    {
        $identifiers = ExtensionPermission::query()
            ->where('extension_id', $extensionId)
            ->pluck('identifier')
            ->all();

        if ($identifiers === []) {
            return 0;
        }

        $count = 0;
        foreach (AdminRole::query()->get(['id', 'permissions']) as $role) {
            $held = is_array($role->permissions) ? $role->permissions : [];
            $count += count(array_intersect($held, $identifiers));
        }

        return $count;
    }

    /**
     * Strip identifiers from every role's permission JSON.
     *
     * Roles store permissions as a JSON array, so this is a read-modify-write
     * per affected role rather than a single UPDATE. The set is small (roles
     * are operator-created, in the tens) and it stays portable across MySQL and
     * SQLite, which the test suite runs on.
     *
     * @param array<int, string> $identifiers
     */
    private function detach(array $identifiers): void
    {
        if ($identifiers === []) {
            return;
        }

        foreach (AdminRole::query()->get() as $role) {
            $held = is_array($role->permissions) ? $role->permissions : [];
            $remaining = array_values(array_diff($held, $identifiers));

            if (count($remaining) === count($held)) {
                continue;
            }

            $role->forceFill(['permissions' => $remaining])->saveQuietly();
        }
    }

    public static function namespaceFor(string $extensionId): string
    {
        return 'ext.' . $extensionId . self::NAMESPACE_SUFFIX;
    }

    public static function flush(): void
    {
        self::$groups = null;
        self::$suspended = null;
    }
}
