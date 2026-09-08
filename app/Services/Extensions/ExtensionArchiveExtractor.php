<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;

/**
 * Safe extraction of an untrusted extension archive.
 *
 * A package archive is attacker-controlled input right up until its signature
 * and checksums verify, and verification happens after extraction — so the
 * extractor itself has to be the boundary. ZipArchive::extractTo() is not: it
 * happily follows an entry named ../../../etc, writes a symlink pointing
 * anywhere, restores a setuid bit, or expands a few kilobytes into gigabytes.
 *
 * Every entry is therefore inspected and streamed individually, with the panel
 * choosing the mode rather than the archive.
 */
class ExtensionArchiveExtractor
{
    /**
     * Unicode characters some filesystems or normalizers fold into a path
     * separator. Rejecting them stops an entry that looks contained from
     * escaping once written.
     */
    private const AMBIGUOUS_SEPARATORS = ["\u{2044}", "\u{2215}", "\u{FF0F}", "\u{29F8}"];

    public function extract(string $archivePath, string $destination): void
    {
        $limits = (array) config('extensions.archive', []);
        $maxEntries = (int) ($limits['max_entries'] ?? 2000);
        $maxFileBytes = (int) ($limits['max_file_bytes'] ?? 8 * 1024 * 1024);
        $maxTotalBytes = (int) ($limits['max_total_bytes'] ?? 96 * 1024 * 1024);
        $maxRatio = (int) ($limits['max_expansion_ratio'] ?? 120);
        $maxDepth = (int) ($limits['max_path_depth'] ?? 12);

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
            throw new DisplayException('The extension archive could not be opened.');
        }

        try {
            if ($zip->numFiles > $maxEntries) {
                throw new DisplayException(sprintf('The extension archive contains %d entries, more than the permitted %d.', $zip->numFiles, $maxEntries));
            }

            $seen = [];
            $totalUncompressed = 0;
            $totalCompressed = 0;

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new DisplayException('The extension archive contains an unreadable entry.');
                }

                $name = (string) $stat['name'];
                $isDirectory = str_ends_with($name, '/');

                $this->assertSafeName($name, $maxDepth);
                $this->assertSafeMode($zip, $index, $name);

                // Case-insensitive collisions matter because the panel may be
                // installed on a case-insensitive filesystem, where two entries
                // that look distinct silently overwrite one another.
                $key = $this->collisionKey($name);
                if (isset($seen[$key])) {
                    throw new DisplayException(sprintf('The extension archive contains a duplicate or case-colliding entry: "%s".', $name));
                }
                $seen[$key] = true;

                if ($isDirectory) {
                    continue;
                }

                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];

                if ($size > $maxFileBytes) {
                    throw new DisplayException(sprintf('The archive entry "%s" expands to more than the permitted %d bytes.', $name, $maxFileBytes));
                }

                $totalUncompressed += $size;
                $totalCompressed += $compressed;

                if ($totalUncompressed > $maxTotalBytes) {
                    throw new DisplayException(sprintf('The extension archive expands to more than the permitted %d bytes.', $maxTotalBytes));
                }
            }

            // Checked once over the whole archive rather than per entry: a
            // single highly-compressible file is normal, an archive that is
            // mostly expansion is a bomb.
            if ($totalCompressed > 0 && ($totalUncompressed / max(1, $totalCompressed)) > $maxRatio) {
                throw new DisplayException('The extension archive has an implausible compression ratio and was rejected.');
            }

            File::ensureDirectoryExists($destination);

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false || str_ends_with((string) $stat['name'], '/')) {
                    continue;
                }

                $this->writeEntry($zip, $index, (string) $stat['name'], $destination, (int) $stat['size']);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Stream one entry to disk through a temporary name, then rename.
     *
     * Streaming bounds memory for a large entry and lets the declared size be
     * enforced as it is read, rather than trusting the header. The rename makes
     * each file appear atomically, so a failure part-way never leaves a
     * half-written file that looks complete.
     */
    private function writeEntry(\ZipArchive $zip, int $index, string $name, string $destination, int $declaredSize): void
    {
        $target = $destination . '/' . $name;
        File::ensureDirectoryExists(dirname($target));

        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new DisplayException(sprintf('The archive entry "%s" could not be read.', $name));
        }

        $temporary = $target . '.part';
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            fclose($stream);

            throw new DisplayException(sprintf('The archive entry "%s" could not be written.', $name));
        }

        try {
            $written = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new DisplayException(sprintf('The archive entry "%s" could not be read.', $name));
                }

                $written += strlen($chunk);
                if ($written > $declaredSize) {
                    throw new DisplayException(sprintf('The archive entry "%s" is larger than the size it declares.', $name));
                }

                if ($chunk !== '' && fwrite($handle, $chunk) === false) {
                    throw new DisplayException(sprintf('The archive entry "%s" could not be written.', $name));
                }
            }

            if ($written !== $declaredSize) {
                throw new DisplayException(sprintf('The archive entry "%s" is smaller than the size it declares.', $name));
            }
        } finally {
            fclose($handle);
            fclose($stream);
        }

        // Modes are chosen by the panel, never restored from the archive, so a
        // package cannot ship an executable or setuid file.
        chmod($temporary, 0o644);

        if (!rename($temporary, $target)) {
            @unlink($temporary);

            throw new DisplayException(sprintf('The archive entry "%s" could not be finalized.', $name));
        }
    }

    /**
     * Key used to detect entries that would collide once written.
     *
     * Unicode normalization is applied when ext-intl is present, so composed
     * and decomposed spellings of the same name are caught; without it the
     * lowercase form still catches the common case, and the traversal and
     * separator checks above do the security-relevant work regardless.
     */
    private function collisionKey(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $name = $normalized;
            }
        }

        return mb_strtolower($name);
    }

    private function assertSafeName(string $name, int $maxDepth): void
    {
        if ($name === '' || strlen($name) > 1024) {
            throw new DisplayException('The extension archive contains an entry with an unusable path length.');
        }

        if (str_contains($name, "\0")) {
            throw new DisplayException('The extension archive contains an entry with a NUL byte in its path.');
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new DisplayException(sprintf('The extension archive contains an absolute path: "%s".', $name));
        }

        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            throw new DisplayException(sprintf('The extension archive contains a drive-qualified path: "%s".', $name));
        }

        if (str_contains($name, '\\')) {
            throw new DisplayException(sprintf('The extension archive contains a backslash in a path: "%s".', $name));
        }

        foreach (self::AMBIGUOUS_SEPARATORS as $separator) {
            if (str_contains($name, $separator)) {
                throw new DisplayException(sprintf('The extension archive contains an ambiguous path separator: "%s".', $name));
            }
        }

        $segments = explode('/', trim($name, '/'));

        if (count($segments) > $maxDepth) {
            throw new DisplayException(sprintf('The extension archive contains a path deeper than %d segments: "%s".', $maxDepth, $name));
        }

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new DisplayException(sprintf('The extension archive contains a traversal path: "%s".', $name));
            }

            if (strlen($segment) > 200) {
                throw new DisplayException(sprintf('The extension archive contains an over-long path segment: "%s".', $name));
            }
        }
    }

    /**
     * Reject anything that is not a plain file or directory.
     *
     * A symlink in an archive is a write primitive: extract one pointing at a
     * core file, then extract "through" it. Devices, FIFOs and sockets have no
     * legitimate place in a package, and setuid/setgid/sticky bits or an
     * executable bit on a regular file are never something a package needs.
     */
    private function assertSafeMode(\ZipArchive $zip, int $index, string $name): void
    {
        $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);
        if ($attributes === false || $opsys !== \ZipArchive::OPSYS_UNIX) {
            // Archives written on other systems carry no unix mode; the panel
            // sets its own mode when writing, so there is nothing to check.
            return;
        }

        $mode = ((int) $attr) >> 16;
        if ($mode === 0) {
            return;
        }

        $type = $mode & 0o170000;

        if ($type === 0o120000) {
            throw new DisplayException(sprintf('The extension archive contains a symbolic link: "%s".', $name));
        }

        if ($type !== 0 && $type !== 0o100000 && $type !== 0o040000) {
            throw new DisplayException(sprintf('The extension archive contains a device, socket or FIFO entry: "%s".', $name));
        }

        if (($mode & 0o7000) !== 0) {
            throw new DisplayException(sprintf('The extension archive contains an entry with setuid, setgid or sticky bits: "%s".', $name));
        }

        if ($type === 0o100000 && ($mode & 0o111) !== 0) {
            throw new DisplayException(sprintf('The extension archive contains an executable file: "%s".', $name));
        }
    }
}
