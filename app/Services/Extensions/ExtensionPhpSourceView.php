<?php

namespace Everest\Services\Extensions;

/**
 * Two views of the same PHP source, at identical byte offsets.
 *
 * Every rule that reads a package's PHP needs one of two things, and they
 * conflict:
 *
 *  - `$code` has comment bodies blanked and string bodies intact. Rules that
 *    read a *literal* out of the source — the table a Schema verb names, the
 *    host a request is sent to — cannot work on anything else.
 *  - `$bare` additionally blanks string bodies, for rules that look for a
 *    *construct* rather than a value. Without it a docblock explaining that a
 *    package never calls eval(), or a message string containing the word
 *    withoutMiddleware, is itself a finding.
 *
 * The blanking preserves both length and newlines exactly, which is what makes
 * the two views interchangeable by offset: a call site can be located in the
 * blanked view, where a table name quoted inside a message string cannot
 * masquerade as a schema change, and its argument then read from the intact
 * view at that same offset.
 *
 * Extracted so the scanner and the migration parser cannot drift apart. Getting
 * this wrong in one of them and not the other is how a rule ends up refusing an
 * install over a sentence in a comment.
 */
final readonly class ExtensionPhpSourceView
{
    private function __construct(
        /** Comments blanked, strings intact. */
        public string $code,
        /** Comments blanked, string bodies blanked. */
        public string $bare,
    ) {
    }

    public static function of(string $source): self
    {
        $code = self::stripComments($source);

        return new self($code, self::blankStrings($code));
    }

    /**
     * Blank comment bodies, preserving newlines and byte offsets.
     *
     * A comment must not be able to produce a finding — a docblock explaining
     * that a package deliberately avoids exec() would otherwise be the reason
     * it cannot be installed — and it must not be able to hide one either,
     * which is why this blanks a copy rather than skipping lines that look like
     * comments.
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : null;

            if ($quote !== null) {
                $out .= $char;

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
                $out .= $char;

                continue;
            }

            if (($char === '/' && ($next === '/' || $next === '*')) || $char === '#') {
                $i = self::blankComment($source, $i, $char === '/' && $next === '*', $out);

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Additionally blank string bodies.
     *
     * The quotes themselves are kept so the surrounding syntax still reads as a
     * call with a string argument; only the contents go.
     */
    private static function blankStrings(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    $out .= ' ';

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    $out .= ' ';

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                    $out .= $char;

                    continue;
                }

                $out .= $char === "\n" ? "\n" : ' ';

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Blank a comment in place, preserving newlines, and return the index of
     * its last consumed byte.
     */
    private static function blankComment(string $source, int $start, bool $block, string &$out): int
    {
        $length = strlen($source);
        $i = $start;

        if ($block) {
            $out .= '  ';
            $i += 2;
            while ($i < $length) {
                if ($source[$i] === '*' && $i + 1 < $length && $source[$i + 1] === '/') {
                    $out .= '  ';

                    return $i + 1;
                }
                $out .= $source[$i] === "\n" ? "\n" : ' ';
                ++$i;
            }

            return $i;
        }

        while ($i < $length && $source[$i] !== "\n") {
            $out .= ' ';
            ++$i;
        }

        return $i - 1;
    }
}
