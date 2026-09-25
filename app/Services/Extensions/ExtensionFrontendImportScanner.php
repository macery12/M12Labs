<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;

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
 *
 * Bare npm imports are a second positive check: they must resolve to a direct
 * dependency pinned by the panel or to a manifest declaration that the
 * requirement preflight already verified. A transitive package is deliberately
 * not part of that surface, even when it happens to appear in pnpm-lock.yaml.
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

    public function __construct(private ExtensionRequirementService $requirementService)
    {
    }

    /**
     * @param array<int, array{path: string, sourcePath: string}> $filePlans
     * @param array<string, string> $declaredNpmPackages
     *
     * @throws DisplayException
     */
    public function assertOnlySdkImports(array $filePlans, array $declaredNpmPackages = []): void
    {
        $boundaryViolations = [];
        $dependencyViolations = [];
        $providedNpmPackages = $this->requirementService->providedNpmPackages();

        foreach ($filePlans as $plan) {
            if (!$this->isFrontendSource($plan['path'])) {
                continue;
            }

            foreach ($this->moduleImportsIn((string) file_get_contents($plan['sourcePath'])) as $specifier) {
                if ($this->isAllowed($plan['path'], $specifier, $providedNpmPackages, $declaredNpmPackages)) {
                    continue;
                }

                $violation = sprintf('%s imports %s', $plan['path'], $specifier);
                if ($this->isBareSpecifier($specifier)) {
                    $package = $this->npmPackageName($specifier);
                    $dependencyViolations[] = $package === null
                        ? $violation . ' (invalid bare package specifier)'
                        : $violation . sprintf(' (package "%s")', $package);
                } else {
                    $boundaryViolations[] = $violation;
                }
            }
        }

        if ($boundaryViolations === [] && $dependencyViolations === []) {
            return;
        }

        // Every violation at once: fixing them one install attempt at a time is
        // a miserable way to learn what the surface is.
        $sections = [];
        if ($boundaryViolations !== []) {
            $sections[] = sprintf("This package's frontend imports panel internals, which are not a supported surface and may change in any release. Import from '%s' instead.\n\n%s", self::ALLOWED_PREFIX, $this->formatViolations($boundaryViolations));
        }
        if ($dependencyViolations !== []) {
            $sections[] = sprintf("This package's frontend imports bare npm packages that the panel does not provide and the manifest does not declare under requirements.npmPackages. Fix a typo, vendor the dependency into the package, or declare a panel-provided package with a compatible semver range.\n\n%s", $this->formatViolations($dependencyViolations));
        }

        throw new DisplayException(implode("\n\n", $sections));
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
    private function isAllowed(string $sourcePath, string $specifier, array $providedNpmPackages, array $declaredNpmPackages): bool
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
            $package = $this->npmPackageName($specifier);

            // Existing packages may use the panel's direct, pinned dependency
            // graph without a declaration. A declaration adds a versioned
            // preflight contract; ExtensionRequirementService has already
            // proved that declared package exists before this scan runs.
            return $package !== null
                && (isset($providedNpmPackages[$package]) || array_key_exists($package, $declaredNpmPackages));
        }

        if (preg_match('~^(frontend/src/extensions/packages/[^/]+)(?:/|$)~', $sourcePath, $matches) !== 1) {
            return false;
        }

        $packageRoot = $matches[1];
        $resolved = $this->normalizePath(dirname($sourcePath) . '/' . $specifier);

        return $resolved === $packageRoot || str_starts_with($resolved, $packageRoot . '/');
    }

    private function isBareSpecifier(string $specifier): bool
    {
        return !str_contains($specifier, '\\')
            && !str_starts_with($specifier, '.')
            && !str_starts_with($specifier, '/')
            && !str_starts_with($specifier, '@/');
    }

    private function npmPackageName(string $specifier): ?string
    {
        if (!$this->isBareSpecifier($specifier)) {
            return null;
        }

        $segments = explode('/', $specifier);
        $package = str_starts_with($specifier, '@')
            ? (isset($segments[1]) ? $segments[0] . '/' . $segments[1] : '')
            : $segments[0];

        if (strlen($package) > ExtensionCapabilityVocabulary::NPM_PACKAGE_MAX_LENGTH
            || preg_match(ExtensionCapabilityVocabulary::NPM_PACKAGE_PATTERN, $package) !== 1
        ) {
            return null;
        }

        return $package;
    }

    /** @param array<int, string> $violations */
    private function formatViolations(array $violations): string
    {
        return implode("\n", array_map(fn (string $line): string => '  - ' . $line, $violations));
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
