<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;

/**
 * Refuses a package whose frontend reaches past the SDK.
 *
 * `frontend/src/extensions-sdk` is the supported surface for extension pages.
 * Everything else under `@/` is panel internals: components that get renamed,
 * stores whose shape changes, services with no compatibility promise. A package
 * importing them compiles today and breaks on a panel release that owed it
 * nothing — and worse, it re-creates exactly the coupling that extracting a
 * feature into a package is meant to remove.
 *
 * The check has to live here rather than in a linter. A linter runs in the
 * publisher's repository, which the panel does not control and cannot inspect;
 * this runs against the bytes actually being installed, after the signature has
 * been verified, on every install and update.
 *
 * Deliberately a textual scan and not a parse. The alternative is a TypeScript
 * parser in PHP, and the thing being detected — an import specifier — is a
 * lexical construct that a regex reads exactly as well. It is a positive check
 * (find every panel-alias import, allow the SDK ones) rather than a search for
 * known-bad names, so a panel module invented next week is caught without this
 * file being touched.
 */
class ExtensionFrontendImportScanner
{
    /** The one panel path a package may import from. */
    private const ALLOWED_PREFIX = '@/extensions-sdk';

    /**
     * Matches the specifier of a static import, a re-export, or a dynamic
     * import(), in each case only when it starts with the panel's `@/` alias:
     *
     *   import x from '@/lib/http'      export { y } from '@/state/flashes'
     *   import '@/styles/thing.css'     await import('@/api/servers')
     */
    private const MODULE_IMPORT = <<<'REGEX'
        ~(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s+|\brequire\s*\(\s*)(['"`])([^'"`\r\n]*)\1~
        REGEX;

    /**
     * @param array<int, array{path: string, sourcePath: string}> $filePlans
     *
     * @throws DisplayException
     */
    public function assertOnlySdkImports(array $filePlans): void
    {
        $violations = [];

        foreach ($filePlans as $plan) {
            if (!$this->isFrontendSource($plan['path'])) {
                continue;
            }

            foreach ($this->moduleImportsIn((string) file_get_contents($plan['sourcePath'])) as $specifier) {
                if ($this->isAllowed($plan['path'], $specifier)) {
                    continue;
                }

                $violations[] = sprintf('%s imports %s', $plan['path'], $specifier);
            }
        }

        if ($violations === []) {
            return;
        }

        // Every violation at once: fixing them one install attempt at a time is
        // a miserable way to learn what the surface is.
        throw new DisplayException(sprintf("This package's frontend imports panel internals, which are not a supported surface and may change in any release. Import from '%s' instead.\n\n%s", self::ALLOWED_PREFIX, implode("\n", array_map(fn (string $line): string => '  - ' . $line, $violations))));
    }

    /**
     * @return array<int, string>
     */
    private function moduleImportsIn(string $source): array
    {
        // Strip comments without treating comment-looking bytes inside a
        // string as comments. A naive `//.*` replacement lets a harmless
        // string earlier on the line hide the real import that follows it, and
        // also lets a doubled slash inside a module path evade the scan.
        $source = $this->stripComments($source);

        if (preg_match_all(trim(self::MODULE_IMPORT), $source, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[2] ?? []));
    }

    private function stripComments(string $source): string
    {
        $result = '';
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($index = 0; $index < $length; ++$index) {
            $character = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : null;

            if ($quote !== null) {
                $result .= $character;

                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $result .= $character;

                continue;
            }

            if ($character === '/' && $next === '/') {
                $result .= '  ';
                ++$index;
                while ($index + 1 < $length && $source[$index + 1] !== "\n") {
                    $result .= ' ';
                    ++$index;
                }

                continue;
            }

            if ($character === '/' && $next === '*') {
                $result .= '  ';
                ++$index;
                while ($index + 1 < $length) {
                    $character = $source[++$index];
                    $next = $index + 1 < $length ? $source[$index + 1] : null;

                    if ($character === '*' && $next === '/') {
                        $result .= '  ';
                        ++$index;
                        break;
                    }

                    // Preserve line boundaries for predictable regex behavior
                    // and diagnostics while masking every other comment byte.
                    $result .= $character === "\n" ? "\n" : ' ';
                }

                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    /**
     * `@/extensions-sdk` itself and anything beneath it. Compared on a segment
     * boundary so a sibling directory whose name merely starts the same way —
     * `@/extensions-sdk-internal` — is not admitted.
     */
    private function isAllowed(string $sourcePath, string $specifier): bool
    {
        if (str_contains($specifier, '\\')) {
            // JavaScript string escapes can spell a forbidden alias without
            // its literal bytes appearing in the source. Module paths never
            // need a backslash, so reject the ambiguous form outright.
            return false;
        }

        if (str_starts_with($specifier, '@/')) {
            // Vite normalizes path segments before resolving the alias. Check
            // that effective path, otherwise an apparently allowed prefix such
            // as `@/extensions-sdk/../lib/http` escapes into panel internals.
            $normalized = $this->normalizePath($specifier);

            return $normalized === self::ALLOWED_PREFIX
                || str_starts_with($normalized, self::ALLOWED_PREFIX . '/');
        }

        // A Vite-root absolute path can reach the panel source tree without
        // using the @/ alias at all (for example /src/lib/http).
        if (str_starts_with($specifier, '/')) {
            return false;
        }

        if (!str_starts_with($specifier, '.')) {
            // Bare imports are resolved from the panel's pinned dependency
            // graph. Packages cannot install their own npm dependencies.
            return true;
        }

        if (preg_match('~^(frontend/src/extensions/packages/[^/]+)(?:/|$)~', $sourcePath, $matches) !== 1) {
            return false;
        }

        $packageRoot = $matches[1];
        $resolved = $this->normalizePath(dirname($sourcePath) . '/' . $specifier);

        return $resolved === $packageRoot || str_starts_with($resolved, $packageRoot . '/');
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isFrontendSource(string $path): bool
    {
        return str_starts_with($path, 'frontend/')
            && preg_match('~\.(ts|tsx|js|jsx|mts|mjs)$~', $path) === 1;
    }
}
