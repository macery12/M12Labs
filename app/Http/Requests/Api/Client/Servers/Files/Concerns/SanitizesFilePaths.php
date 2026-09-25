<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files\Concerns;

/**
 * Shared path sanitizer for the client file-manager requests.
 *
 * Every daemon file endpoint takes paths in one of exactly two shapes, and
 * getting the two confused is not a theoretical problem: rename used to hand
 * back a root-prefixed target for an endpoint that joins the root itself, so
 * the daemon looked below /plugins/plugins, matched nothing, and still answered
 * 200. The two shapes are:
 *
 *  - *root-relative*: `root` names a directory and each entry is the remainder
 *    below it, which the daemon joins onto the root (rename, delete, chmod,
 *    compress, decompress, create-folder).
 *  - *server-absolute*: a single path from the server root, whose parent and
 *    file name the daemon splits itself (copy, and the `file` parameter on
 *    read, download and write).
 *
 * Escaping the server root is ultimately prevented by the daemon's
 * capability-based filesystem, so this layer exists to reject nonsense early
 * and to keep one definition of a legal path instead of six.
 */
trait SanitizesFilePaths
{
    /**
     * Normalize a directory into a leading-slash, collapsed path. A null or
     * empty root means the server root.
     *
     * @throws \InvalidArgumentException
     */
    protected function normalizeRoot(?string $path): string
    {
        $clean = $this->toForwardSlashes((string) $path);
        $clean = ltrim($clean, '/');

        if ($clean === '') {
            return '/';
        }

        return '/' . $this->collapseSegments($clean);
    }

    /**
     * Sanitize an entry named relative to $root and return the remainder below
     * it — which is what the daemon expects to join back onto the root.
     *
     * @throws \InvalidArgumentException
     */
    protected function sanitizeRootRelativePath(string $root, string $path): string
    {
        $clean = $this->toForwardSlashes($path);

        if ($clean === '') {
            throw new \InvalidArgumentException('Invalid file name or path.');
        }

        $this->rejectAbsoluteOrDriveQualified($clean);

        $rootPrefix = trim($root, '/');
        $combined = $rootPrefix !== '' ? $rootPrefix . '/' . $clean : $clean;
        $normalized = $this->collapseSegments($combined);

        if ($rootPrefix !== '' && !str_starts_with($normalized, $rootPrefix . '/')) {
            throw new \InvalidArgumentException('Path traversal is not allowed.');
        }

        return $rootPrefix !== '' ? substr($normalized, strlen($rootPrefix) + 1) : $normalized;
    }

    /**
     * Sanitize a single path given from the server root, returning it with a
     * leading slash.
     *
     * @throws \InvalidArgumentException
     */
    protected function sanitizeServerAbsolutePath(string $path, bool $allowRoot = false): string
    {
        $clean = $this->toForwardSlashes($path);

        if (preg_match('/^[a-zA-Z]:/', $clean) === 1) {
            throw new \InvalidArgumentException('Drive letters are not allowed.');
        }

        $clean = ltrim($clean, '/');

        if ($clean === '') {
            // The server root is a meaningful target for a directory download,
            // and meaningless for reading or writing a file.
            if ($allowRoot) {
                return '/';
            }

            throw new \InvalidArgumentException('Invalid file name or path.');
        }

        return '/' . $this->collapseSegments($clean);
    }

    /**
     * Sanitize a bare file name — no directory separators at all.
     *
     * @throws \InvalidArgumentException
     */
    protected function sanitizeFileName(string $name): string
    {
        $clean = $this->toForwardSlashes($name);

        if ($clean === '' || str_contains($clean, '/')) {
            throw new \InvalidArgumentException('Invalid file name or path.');
        }

        return $this->collapseSegments($clean);
    }

    /**
     * Sanitize `root` plus a flat list of root-relative names, merging the
     * cleaned values back over the request. Used by delete and compress.
     */
    protected function sanitizeRootAndFileList(\Illuminate\Validation\Validator $validator, string $key = 'files'): void
    {
        $root = $this->sanitizedRoot($validator);
        if ($root === null) {
            return;
        }

        $sanitized = [];

        foreach ($this->input($key, []) as $file) {
            try {
                $sanitized[] = $this->sanitizeRootRelativePath($root, (string) $file);
            } catch (\InvalidArgumentException $ex) {
                $validator->errors()->add($key, $ex->getMessage());

                return;
            }
        }

        $this->merge(['root' => $root, $key => $sanitized]);
    }

    /**
     * Sanitize `root` plus a list of entries that carry their path under a
     * sub-key, preserving every other field on the entry. Used by chmod.
     */
    protected function sanitizeRootAndKeyedFileList(\Illuminate\Validation\Validator $validator, string $key = 'files', string $subKey = 'file'): void
    {
        $root = $this->sanitizedRoot($validator);
        if ($root === null) {
            return;
        }

        $sanitized = [];

        foreach ($this->input($key, []) as $entry) {
            if (!is_array($entry)) {
                $validator->errors()->add($key, 'Invalid file name or path.');

                return;
            }

            try {
                $sanitized[] = array_merge($entry, [
                    $subKey => $this->sanitizeRootRelativePath($root, (string) ($entry[$subKey] ?? '')),
                ]);
            } catch (\InvalidArgumentException $ex) {
                $validator->errors()->add($key, $ex->getMessage());

                return;
            }
        }

        $this->merge(['root' => $root, $key => $sanitized]);
    }

    /**
     * Sanitize `root` plus a single root-relative entry. Used by decompress.
     */
    protected function sanitizeRootAndSingleFile(\Illuminate\Validation\Validator $validator, string $key = 'file'): void
    {
        $root = $this->sanitizedRoot($validator);
        if ($root === null) {
            return;
        }

        try {
            $file = $this->sanitizeRootRelativePath($root, (string) $this->input($key, ''));
        } catch (\InvalidArgumentException $ex) {
            $validator->errors()->add($key, $ex->getMessage());

            return;
        }

        $this->merge(['root' => $root, $key => $file]);
    }

    /**
     * Sanitize one server-absolute path field in place. Used by copy and by the
     * `file` parameter on read, download and write.
     */
    protected function sanitizeAbsolutePathField(\Illuminate\Validation\Validator $validator, string $key, bool $allowRoot = false): void
    {
        $value = $this->input($key);

        if (!is_string($value)) {
            return;
        }

        try {
            $this->merge([$key => $this->sanitizeServerAbsolutePath($value, $allowRoot)]);
        } catch (\InvalidArgumentException $ex) {
            $validator->errors()->add($key, $ex->getMessage());
        }
    }

    /** Normalized root, or null once the failure has been recorded. */
    private function sanitizedRoot(\Illuminate\Validation\Validator $validator): ?string
    {
        try {
            return $this->normalizeRoot($this->input('root'));
        } catch (\InvalidArgumentException $ex) {
            $validator->errors()->add('root', $ex->getMessage());

            return null;
        }
    }

    private function toForwardSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function rejectAbsoluteOrDriveQualified(string $path): void
    {
        if (preg_match('/^[a-zA-Z]:/', $path) === 1) {
            throw new \InvalidArgumentException('Drive letters are not allowed.');
        }

        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Absolute paths are not allowed.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function collapseSegments(string $path): string
    {
        if (str_contains($path, '//')) {
            throw new \InvalidArgumentException('Name contains invalid characters.');
        }

        // Note the explicit predicate: a bare array_filter() would also discard
        // a segment named "0", silently dropping a directory legitimately
        // called 0.
        $segments = array_filter(explode('/', $path), fn (string $segment) => $segment !== '');
        $safeSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new \InvalidArgumentException('Path traversal is not allowed.');
            }

            // Reject control characters (NUL included) rather than allowlisting
            // a narrow ASCII set. An allowlist of /^[A-Za-z0-9._ -]+$/ used to
            // turn away ordinary names — "map (1).zip", "café.txt", "a+b.cfg" —
            // and, applied to a root, made everything inside a folder holding
            // one of those characters unreachable.
            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new \InvalidArgumentException('Name contains invalid characters.');
            }

            $safeSegments[] = $segment;
        }

        if (count($safeSegments) === 0) {
            throw new \InvalidArgumentException('Invalid file name or path.');
        }

        return implode('/', $safeSegments);
    }
}
