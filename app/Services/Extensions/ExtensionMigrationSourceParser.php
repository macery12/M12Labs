<?php

namespace Everest\Services\Extensions;

/**
 * Reads the schema changes a package's PHP describes, without running it.
 *
 * Two callers, one implementation, which is the point: the install gate refuses
 * an operation that names a table outside the package's namespace, and the
 * database plan shows an operator what an install or update is about to do. If
 * those two disagreed, the gate would be refusing something the preview never
 * mentioned, or — far worse — the preview would be reassuring about an
 * operation the gate never looked at. `Schema::create` alone was the old rule,
 * and that second case is exactly what it produced: a migration could drop a
 * table and the operator approving it was shown an empty list.
 *
 * It is a source-level read, so it is defence-in-depth against accidents rather
 * than a sandbox. A table name built at runtime is invisible to it, and
 * deliberate evasion is equivalent to shipping malicious PHP, which manual
 * review owns. What it does guarantee is that nothing *written plainly* is
 * silently absent from what an operator approves.
 */
class ExtensionMigrationSourceParser
{
    /** Schema verbs that name a table as their first argument. */
    private const VERBS = '~Schema::\s*(create|table|drop|dropIfExists|rename)\s*\(\s*[\'"]([^\'"]+)[\'"]~';

    /** The same, anchored, for re-reading one call site in the strings-intact view. */
    private const VERB_AT = '~\GSchema::\s*(create|table|drop|dropIfExists|rename)\s*\(\s*[\'"]([^\'"]*)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"])?~';

    /**
     * Statements whose effect this cannot read. Counted rather than parsed: the
     * honest thing to tell an operator is that the list they are looking at is
     * incomplete, not to pretend it is not.
     */
    private const RAW = '~(DB::statement|DB::unprepared)\s*\(~';

    /**
     * Schema operations in source order.
     *
     * Call sites are located in the strings-blanked view, so a table name
     * quoted inside a message cannot masquerade as a schema change; the names
     * are then read back from the strings-intact view at the same offset.
     *
     * @return array<int, array{verb: string, table: string, renameTo: ?string}>
     */
    public function operations(string $source): array
    {
        $view = ExtensionPhpSourceView::of($source);
        $operations = [];

        if (!preg_match_all(self::VERBS, $view->bare, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            if (!preg_match(self::VERB_AT, $view->code, $real, 0, $match[0][1])) {
                // The views disagree, which should be impossible while the
                // blanking preserves length. Skipping is the safe read for the
                // scanner — a rule that cannot name its table cannot refuse an
                // install — and the plan's raw-statement count is what tells an
                // operator the preview may be short.
                continue;
            }

            if ($real[2] === '') {
                continue;
            }

            $operations[] = [
                'verb' => $real[1],
                'table' => $real[2],
                // Only rename has a second table, and it is the one that
                // decides where the table ends up.
                'renameTo' => $real[1] === 'rename' && ($real[3] ?? '') !== '' ? $real[3] : null,
            ];
        }

        return $operations;
    }

    /**
     * Every table name an operation touches, including a rename's destination.
     *
     * @param array{verb: string, table: string, renameTo: ?string} $operation
     *
     * @return array<int, string>
     */
    public function tablesTouchedBy(array $operation): array
    {
        return array_values(array_filter([$operation['table'], $operation['renameTo']]));
    }

    /** How many statements in this source the parser cannot account for. */
    public function rawStatementCount(string $source): int
    {
        return preg_match_all(self::RAW, ExtensionPhpSourceView::of($source)->bare) ?: 0;
    }
}
