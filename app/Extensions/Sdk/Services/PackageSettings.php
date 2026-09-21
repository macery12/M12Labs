<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Everest\Models\ExtensionConfig;
use Everest\Extensions\Sdk\DisplayException;
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
     * keys from their declared defaults.
     *
     * Validation is `ExtensionSettingsValidator`, the same one the settings API
     * applies to an administrator's edit. So a package cannot write a key it
     * did not declare, cannot write a value outside its declared type, range or
     * enum, and cannot write a field declared `visibility: secret` -- those go
     * to {@see PackageSecrets}, and this column is returned by the catalog API.
     *
     * Declared flags are recomputed from this column on the next read, so a
     * save that flips `agent_enabled` is visible to the frontend's
     * `refreshExtensionFlags()` without a reload. Nothing is cached here.
     *
     * @param array<string, mixed> $changes
     *
     * @throws DisplayException when the package is not in the runtime plan, or
     *                          a key is undeclared or secret
     * @throws \Illuminate\Validation\ValidationException on a value that fails its rule
     */
    public function save(array $changes): void
    {
        $entry = app(ExtensionRuntimePlanService::class)->entry($this->extensionId);

        if ($entry === null) {
            // Not in the plan means disabled, quarantined, tampered or
            // incompatible -- the package is not supposed to be executing at
            // all. Writing unvalidated settings on the way out would be the
            // one path that skips the schema, so refuse instead.
            throw new DisplayException(sprintf('The extension [%s] is not currently loadable, so its settings cannot be saved.', $this->extensionId));
        }

        $merged = array_replace($this->values, $changes);

        $validated = app(ExtensionSettingsValidator::class)->validate($entry->capabilities, $merged);

        ExtensionConfig::updateOrCreateConfig($this->extensionId, ['settings' => $validated]);

        $this->values = $validated;
    }
}
