<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;

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

    /** Blueprint calls which create a foreign-key constraint. */
    private const FOREIGN = '~->\s*(foreignIdFor|foreignUuidFor|foreignUlidFor|foreignId|foreignUuid|foreignUlid|foreign)\s*\(~';

    /** A migration's rollback method, located in the blanked view. */
    private const DOWN = '~\bfunction\s+down\s*\(~i';

    /**
     * The source with every `down()` body blanked, at the same length.
     *
     * For the operator's preview only. An install or update runs `up()`;
     * `down()` runs when that same operation fails and is reverted, or through
     * the audited uninstall-and-drop-data flow, which has its own preview. Read
     * as part of the forward plan, a conventional `down()` — `dropIfExists` for
     * every table `up()` creates — turned reinstalling `ai` over its kept data
     * into a screen listing all eight tables, with row counts, as "to be
     * deleted by this update". The install gate keeps reading the whole file: a
     * `down()` that names a core table is refused whether or not it ever runs.
     *
     * Only the body goes, so anything outside it — a helper `up()` calls
     * included — is still read. A body whose braces cannot be matched is left
     * alone, because over-reporting is the safe way for a preview to be wrong.
     */
    public function withoutRollback(string $source): string
    {
        $bare = ExtensionPhpSourceView::of($source)->bare;

        if (!preg_match_all(self::DOWN, $bare, $matches, PREG_OFFSET_CAPTURE)) {
            return $source;
        }

        foreach ($matches[0] as [$signature, $offset]) {
            $open = strpos($bare, '{', $offset + strlen($signature));
            $semicolon = strpos($bare, ';', $offset);

            // An abstract or interface declaration has no body, and the next
            // brace belongs to some other method.
            if ($open === false || ($semicolon !== false && $semicolon < $open)) {
                continue;
            }

            $close = self::closingBrace($bare, $open);

            if ($close === null) {
                continue;
            }

            for ($i = $open + 1; $i < $close; ++$i) {
                if ($source[$i] !== "\n") {
                    $source[$i] = ' ';
                }
            }
        }

        return $source;
    }

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

    /**
     * Literal foreign-key references in the source.
     *
     * A result with a null table/column is deliberate: a constraint was found,
     * but its target was dynamic or used a shape this source-level validator
     * cannot prove safe. Callers must refuse that constraint rather than
     * silently treating "not understood" as "not present".
     *
     * The foreign-column helpers only become constraints when followed by
     * `constrained()`. Without it they are column declarations and are not
     * returned here. `foreignIdFor(Model::class)->constrained()` is returned as
     * opaque and therefore refused: a model class is not a literal database
     * ownership boundary.
     *
     * @return array<int, array{localColumn: ?string, table: ?string, referencedColumn: ?string, onDelete: ?string}>
     */
    public function foreignKeyReferences(string $source): array
    {
        $view = ExtensionPhpSourceView::of($source);
        $references = [];

        if (!preg_match_all(self::FOREIGN, $view->bare, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $method = $match[1][0];
            $chain = $this->callChainAt($view->code, $match[0][1]);

            if ($method !== 'foreign' && !preg_match('~->\s*constrained\s*\(~', $chain)) {
                continue;
            }

            $localColumn = $this->firstLiteralArgument($chain, $method);
            $table = null;
            $referencedColumn = null;

            if ($method === 'foreign') {
                $table = $this->firstLiteralArgument($chain, 'on');
                $referencedColumn = $this->firstLiteralArgument($chain, 'references');
            } else {
                $arguments = $this->callArguments($chain, 'constrained');
                $target = $arguments === null ? ['table' => null, 'column' => null] : $this->constrainedTarget($arguments);

                $table = $target['table'] ?? ($localColumn === null ? null : $this->inferConstrainedTable($localColumn));
                $referencedColumn = $target['column'] ?? 'id';
            }

            $references[] = [
                'localColumn' => $localColumn,
                'table' => $table,
                'referencedColumn' => $referencedColumn,
                'onDelete' => $this->deleteAction($chain),
            ];
        }

        return $references;
    }

    /** How many statements in this source the parser cannot account for. */
    public function rawStatementCount(string $source): int
    {
        return preg_match_all(self::RAW, ExtensionPhpSourceView::of($source)->bare) ?: 0;
    }

    /** Return one fluent call chain, ending at its first unquoted semicolon. */
    private function callChainAt(string $source, int $offset): string
    {
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($i = $offset; $i < $length; ++$i) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === ';') {
                return substr($source, $offset, $i - $offset + 1);
            }
        }

        return substr($source, $offset);
    }

    private function firstLiteralArgument(string $chain, string $method): ?string
    {
        $method = preg_quote($method, '~');

        if (!preg_match(sprintf('~(?:^|->)\s*%s\s*\(\s*(?:\w+\s*:\s*)?([\'\"])([^\'\"]+)\1~s', $method), $chain, $match)) {
            return null;
        }

        return $match[2];
    }

    private function callArguments(string $chain, string $method): ?string
    {
        $method = preg_quote($method, '~');
        if (!preg_match(sprintf('~->\s*%s\s*\(([^)]*)\)~s', $method), $chain, $match)) {
            return null;
        }

        return $match[1];
    }

    /** @return array{table: ?string, column: ?string} */
    private function constrainedTarget(string $arguments): array
    {
        $named = [];
        $positional = [];

        if (preg_match_all('~(?:^|,)\s*(?:(\w+)\s*:\s*)?([\'\"])([^\'\"]+)\2~s', $arguments, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] !== '') {
                    $named[$match[1]] = $match[3];
                } else {
                    $positional[] = $match[3];
                }
            }
        }

        return [
            'table' => $named['table'] ?? $positional[0] ?? null,
            'column' => $named['column'] ?? $positional[1] ?? null,
        ];
    }

    private function inferConstrainedTable(string $column): string
    {
        $stem = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;

        return Str::plural($stem);
    }

    private function deleteAction(string $chain): ?string
    {
        if (preg_match('~->\s*onDelete\s*\(\s*([\'\"])([^\'\"]+)\1\s*\)~s', $chain, $match)) {
            return strtolower(str_replace(['_', '-'], ' ', trim($match[2])));
        }

        foreach (['cascade' => 'cascade', 'null' => 'set null', 'restrict' => 'restrict', 'noAction' => 'no action'] as $method => $action) {
            if (preg_match(sprintf('~->\s*%sOnDelete\s*\(\s*\)~', $method), $chain)) {
                return $action;
            }
        }

        return null;
    }

    /** Offset of the brace closing the one at $open, in a strings-blanked view. */
    private static function closingBrace(string $bare, int $open): ?int
    {
        $depth = 0;
        $length = strlen($bare);

        for ($i = $open; $i < $length; ++$i) {
            if ($bare[$i] === '{') {
                ++$depth;
            } elseif ($bare[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
