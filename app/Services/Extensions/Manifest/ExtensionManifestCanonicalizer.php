<?php

namespace Everest\Services\Extensions\Manifest;

/**
 * Deterministic serialization of a manifest, for hashing and signing.
 *
 * Two manifests that differ only in key order or whitespace must produce
 * identical bytes, otherwise a signature computed by the publisher would not
 * verify against the document the panel parsed. Follows the JSON Canonicalization
 * Scheme in the ways that matter here: recursive key sort, no insignificant
 * whitespace, no escaped slashes or unicode.
 *
 * `integrity.signature` is excluded — the signature cannot cover itself.
 */
final class ExtensionManifestCanonicalizer
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function canonicalize(array $manifest): string
    {
        unset($manifest['integrity']['signature']);

        // An integrity block reduced to nothing but the stripped signature must
        // canonicalize the same as no integrity block at all, so that signing
        // and verification see identical bytes.
        if (isset($manifest['integrity']) && $manifest['integrity'] === []) {
            unset($manifest['integrity']);
        }

        return (string) json_encode(
            $this->sort($manifest),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Message a publisher signs and the panel verifies. Domain-separated so a
     * signature over one artifact can never be replayed as one over another.
     */
    public function signingMessage(string $extensionId, string $version, string $canonicalManifest, string $archiveSha256): string
    {
        return implode("\n", [
            'm12labs-ext-v3',
            $extensionId,
            $version,
            hash('sha256', $canonicalManifest),
            strtolower($archiveSha256),
        ]);
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // List order is meaningful (files, backoff steps, enum choices) and is
        // preserved; only object keys are sorted.
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
    }
}
