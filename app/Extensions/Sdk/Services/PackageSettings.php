<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Facades\Activity;
use Everest\Models\AdminRole;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Extensions\Sdk\DisplayException;
use Everest\Services\Authorization\AdminAuthorizer;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Services\Extensions\ExtensionSettingsValidator;
use Everest\Services\Extensions\ExtensionRuntimePlanService;

/**
 * The extension's own settings, as saved by an administrator.
 *
 * Typed accessors rather than a raw array, because the stored JSON is whatever
 * was last written: a key can be absent because the operator never opened the
 * page, or present and of the wrong type because the manifest changed shape in
 * an update. Every accessor takes the default the caller wants, so a package
 * never has to decide what a missing value means twice.
 *
 * {@see save()} is the one writer, and it is narrow on purpose: a package may
 * write the keys it declared and nothing else, through the same validator the
 * settings API uses. It exists because a package with its own settings UI --
 * more pages than the panel's generated form can carry -- would otherwise be
 * able to read its configuration but never save it.
 *
 * Secrets are deliberately absent. `extension_configs.settings` is a plain JSON
 * column the catalog API returns, so anything sensitive belongs in
 * {@see PackageSecrets} instead -- which is why the manifest's setting types
 * have no `password`.
 */
final class PackageSettings
{
    /** @param array<string, mixed> $values */
    private function __construct(
        private string $extensionId,
        private array $values,
        private bool $enabled,
        private ?ExtensionConfig $config,
    ) {
    }

    public static function for(string $extensionId): self
    {
        ExtensionCallerGuard::assertCallerIs($extensionId);

        $config = ExtensionConfig::getByExtensionId($extensionId);
        $settings = $config?->settings;

        return new self(
            $extensionId,
            is_array($settings) ? $settings : [],
            $config !== null && (bool) $config->enabled,
            $config,
        );
    }

    /**
     * Whether an administrator has this extension switched on.
     *
     * The same row as the settings, which is why it lives here rather than in a
     * class of its own. Routes, pages, hooks and scheduled tasks are already
     * gated on this by the panel, so most code never needs it — an artisan
     * command is the exception, because it can be run by hand at any time and
     * the scheduler's own gate does not apply then.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether this extension is available for one particular server.
     *
     * Enabled, and the server's egg or nest within whatever the operator scoped
     * the extension to. The panel's `extensions.access` middleware already
     * applies this to every client route, so a controller reached through one
     * does not need to ask again — this is for the paths that middleware does
     * not cover, such as a queued job acting on a server some time after the
     * request that scheduled it.
     */
    public function allowsServer(Server $server): bool
    {
        return $this->config !== null && $this->config->isServerEligible($server);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Anything the operator could have meant as true. The settings column has
     * been written by a JSON form, a manifest default and an older version of
     * the same package, so "1", "true" and true all occur in practice.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public function list(string $key, array $default = []): array
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Save changes to this extension's own declared settings.
     *
     * A **merge**, not a replace: the changes are folded over what is stored
     * and the result is validated as a whole. That is what makes it safe for a
     * package whose settings UI is several pages -- saving one page must not
     * reset the keys the other pages own, which is exactly what would happen
     * if a partial payload went to the validator alone, since it fills absent
     * keys from their declared defaults. The stored values are re-read under a
     * row lock for the merge, not taken from when this instance was built, so
     * two pages saving at once cannot undo each other.
     *
     * Validation is `ExtensionSettingsValidator`, the same one the settings API
     * applies to an administrator's edit. So a package cannot write a key it
     * did not declare, cannot write a value outside its declared type, range or
     * enum, and cannot write a field declared `visibility: secret` -- those go
     * to {@see PackageSecrets}, and this column is returned by the catalog API.
     *
     * **Who.** Pass `$actor` when an administrator made the change on the
     * package's own settings page: they must hold `extensions.update`, the
     * permission the panel's settings form requires, so a package page cannot
     * widen who may change configuration. Leave it null for the package's own
     * bookkeeping (a migration adopting old values, a calibration it records).
     * Either way the change is written to the activity log -- the keys that
     * changed, never their values, and `via` naming the package.
     *
     * Declared flags are recomputed from this column on the next read, so a
     * save that flips `agent_enabled` is visible to the frontend's
     * `refreshExtensionFlags()` without a reload. Nothing is cached here.
     *
     * @param array<string, mixed> $changes
     *
     * @throws DisplayException when the package is not in the runtime plan, a
     *                          key is undeclared or secret, or the actor may
     *                          not manage extensions
     * @throws \Illuminate\Validation\ValidationException on a value that fails its rule
     */
    public function save(array $changes, ?User $actor = null): void
    {
        $entry = app(ExtensionRuntimePlanService::class)->entry($this->extensionId);

        if ($entry === null) {
            // Not in the plan means disabled, quarantined, tampered or
            // incompatible -- the package is not supposed to be executing at
            // all. Writing unvalidated settings on the way out would be the
            // one path that skips the schema, so refuse instead.
            throw new DisplayException(sprintf('The extension [%s] is not currently loadable, so its settings cannot be saved.', $this->extensionId));
        }

        if ($actor !== null && !app(AdminAuthorizer::class)->hasCapability($actor, AdminRole::EXTENSIONS_UPDATE)) {
            throw new DisplayException('Changing extension settings requires the extensions update permission.');
        }

        [$before, $validated] = DB::transaction(function () use ($entry, $changes): array {
            $stored = ExtensionConfig::query()
                ->where('extension_id', $this->extensionId)
                ->lockForUpdate()
                ->value('settings');
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;
            $before = is_array($stored) ? $stored : [];

            $validated = app(ExtensionSettingsValidator::class)->validate(
                $entry->capabilities,
                array_replace($before, $changes),
            );

            ExtensionConfig::updateOrCreateConfig($this->extensionId, ['settings' => $validated]);

            return [$before, $validated];
        });

        $this->values = $validated;

        // Against what was in effect, not what was stored: the validator fills
        // an absent key from its declared default, and that is not a change
        // anybody made.
        $effective = $before;
        foreach ($entry->capabilities->settings as $field) {
            if (!array_key_exists($field->key, $effective) && $field->default !== null) {
                $effective[$field->key] = $field->default;
            }
        }

        $changed = array_keys(array_filter(
            $validated,
            fn (mixed $value, string $key): bool => !array_key_exists($key, $effective) || $effective[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        sort($changed, SORT_STRING);

        $event = Activity::event('admin:extensions:settings-update');
        if ($actor !== null) {
            $event->actor($actor);
        }

        $event->property('extension_id', $this->extensionId)
            ->property('keys', $changed)
            ->property('via', $this->extensionId)
            ->log();
    }
}
