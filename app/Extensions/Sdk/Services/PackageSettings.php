<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Everest\Models\ExtensionConfig;

/**
 * The extension's own settings, as saved by an administrator.
 *
 * Reads only, and typed accessors rather than a raw array, because the stored
 * JSON is whatever was last written: a key can be absent because the operator
 * never opened the page, or present and of the wrong type because the manifest
 * changed shape in an update. Every accessor takes the default the caller
 * wants, so a package never has to decide what a missing value means twice.
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
}
