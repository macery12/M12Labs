<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Services\Extensions\ExtensionSecretStore;

/**
 * The encrypted secret store, scoped to one extension.
 *
 * Reads only. A secret is entered and rotated by an administrator through the
 * extension's admin page; a package writing its own credentials would mean a
 * value in the store that no operator put there.
 *
 * Only keys the manifest declares under `capabilities.secrets` exist, so a key
 * this package never declared returns null rather than reaching another
 * extension's value. The extension id is bound at construction rather than
 * passed per call, which is what keeps `get()` from being an arbitrary lookup
 * across the whole table -- though note this is ergonomics and defence in
 * depth, not an identity boundary: package PHP is trusted code and the real
 * guarantees are review and signing.
 */
final class PackageSecrets
{
    private function __construct(private string $extensionId, private ExtensionSecretStore $store)
    {
    }

    public static function for(string $extensionId): self
    {
        return new self($extensionId, app(ExtensionSecretStore::class));
    }

    /** The decrypted value, or null when unset or undeclared. */
    public function get(string $key): ?string
    {
        return $this->store->get($this->extensionId, $key);
    }

    /** Whether a value is present, without decrypting it. */
    public function configured(string $key): bool
    {
        return $this->store->configured($this->extensionId, $key);
    }
}
