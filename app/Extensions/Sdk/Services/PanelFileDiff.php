<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Services\Files\FileDiffService;

/**
 * Compare two versions of a server file.
 *
 * For a package that proposes a change and wants an operator to approve it
 * before it is written -- a config rewrite, a startup edit, a templated file.
 * Showing the diff is what makes that approval mean something; showing the new
 * contents alone asks somebody to spot the difference by eye.
 *
 * Pure text work: nothing here reads or writes a file. Fetch the current
 * contents with {@see ServerFiles}, produce the replacement however the package
 * likes, and pass both strings in. The SDK's frontend `DiffView` renders the
 * result.
 */
final class PanelFileDiff
{
    private function __construct(private FileDiffService $diffs)
    {
    }

    public static function reader(): self
    {
        return new self(app(FileDiffService::class));
    }

    /**
     * Whether this filename looks like something a diff would make sense of.
     *
     * Worth asking first. A binary file produces a diff that is technically
     * correct and completely unreadable, and the honest thing to show an
     * operator about a changed jar is that it changed, not 40,000 lines of
     * mojibake.
     */
    public function isTextFile(string $filename): bool
    {
        return $this->diffs->isTextFile($filename);
    }

    /**
     * The line-by-line difference between two versions of one file.
     *
     * The filename is used to decide how to read the contents, not to find
     * them.
     *
     * @return array<string, mixed>
     */
    public function calculate(string $originalContent, string $newContent, string $filename): array
    {
        return $this->diffs->calculateDiff($originalContent, $newContent, $filename);
    }
}
