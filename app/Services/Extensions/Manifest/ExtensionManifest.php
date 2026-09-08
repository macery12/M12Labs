<?php

namespace Everest\Services\Extensions\Manifest;

/**
 * A validated manifest v3 document.
 *
 * Nothing outside the parser reads raw manifest arrays. Every consumer —
 * the catalog, the install/update/uninstall services, the route loaders, the
 * hook dispatcher, the permission and queue registries — takes this object, so
 * a manifest shape change has one place to land rather than a dozen
 * `Arr::get($manifest, ...)` call sites to hunt down.
 */
final readonly class ExtensionManifest implements \JsonSerializable
{
    public const VERSION = 3;

    /**
     * @param array<int, string> $compatiblePanelVersions
     * @param array<string, mixed> $defaults
     * @param array<int, array{path: string, sha256: string}> $files
     * @param array<string, mixed> $requirements
     * @param array<string, mixed>|null $integrity
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $packageId,
        public string $name,
        public string $description,
        public string $icon,
        public ?string $publisher,
        public ?string $license,
        public ?string $homepage,
        public array $defaults,
        public array $compatiblePanelVersions,
        public ExtensionCapabilitySet $capabilities,
        public array $requirements,
        public array $files,
        public ?array $integrity,
        public array $raw,
    ) {
    }

    public function defaultEnabled(): bool
    {
        return (bool) ($this->defaults['enabled'] ?? false);
    }

    /** @return array<int, int> */
    public function defaultAllowedNests(): array
    {
        return array_values(array_filter((array) ($this->defaults['allowedNests'] ?? []), 'is_int'));
    }

    /** @return array<int, int> */
    public function defaultAllowedEggs(): array
    {
        return array_values(array_filter((array) ($this->defaults['allowedEggs'] ?? []), 'is_int'));
    }

    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        $settings = $this->defaults['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @return array<int, string> */
    public function filePaths(): array
    {
        return array_map(fn (array $file): string => $file['path'], $this->files);
    }

    public function signature(): ?string
    {
        return isset($this->integrity['signature']) ? (string) $this->integrity['signature'] : null;
    }

    public function signingKeyId(): ?string
    {
        return isset($this->integrity['keyId']) ? (string) $this->integrity['keyId'] : null;
    }

    /**
     * Digest of the whole document, used to detect a stored manifest that has
     * been altered on disk since it was installed.
     */
    public function hash(): string
    {
        return hash('sha256', (new ExtensionManifestCanonicalizer())->canonicalize($this->raw));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
