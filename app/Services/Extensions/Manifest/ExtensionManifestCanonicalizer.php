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
     * Canonicalize the manifest directly from its JSON representation.
     *
     * json_decode(..., true) cannot distinguish an empty JSON object (`{}`)
     * from an empty JSON array (`[]`): both become an empty PHP array. That
     * distinction is part of the signed bytes, so verification must retain it.
     * Parsing and capability validation still consume associative arrays; only
     * signature canonicalization uses this type-preserving document path.
     */
    public function canonicalizeJson(string $manifestJson): string
    {
        $manifest = json_decode($manifestJson, false, 512, JSON_THROW_ON_ERROR);
        if (!$manifest instanceof \stdClass) {
            throw new \JsonException('An extension manifest must be a JSON object.');
        }

        if (isset($manifest->integrity) && $manifest->integrity instanceof \stdClass) {
            unset($manifest->integrity->signature);
            if (get_object_vars($manifest->integrity) === []) {
                unset($manifest->integrity);
            }
        }

        return (string) json_encode(
            $this->sort($manifest),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Message a publisher signs and the panel verifies. Domain-separated so a
     * signature over one artifact can never be replayed as one over another.
     *
     * The archive's own sha256 is deliberately absent. The signature ships
     * inside the archive, so covering the archive hash would be circular —
     * inserting the signature changes the hash it just committed to. Nothing is
     * lost by leaving it out: the canonical manifest carries a sha256 for every
     * file, the installer copies only files the manifest lists and verifies
     * each one, so signing the manifest already commits to everything that
     * reaches the panel. Archive-level integrity for a repository install comes
     * from the registry's own checksum, which is checked before extraction.
     */
    public function signingMessage(string $extensionId, string $version, string $canonicalManifest): string
    {
        return implode("\n", [
            'm12labs-ext-v3',
            $extensionId,
            $version,
            hash('sha256', $canonicalManifest),
        ]);
    }

    private function sort(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            $sorted = new \stdClass();
            foreach ($properties as $key => $item) {
                $sorted->{$key} = $this->sort($item);
            }

            return $sorted;
        }

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
