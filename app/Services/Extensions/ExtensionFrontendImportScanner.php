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
    private const PANEL_IMPORT = <<<'REGEX'
        ~(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s+|\brequire\s*\(\s*)(['"])(@/[^'"]*)\1~
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

            foreach ($this->panelImportsIn((string) file_get_contents($plan['sourcePath'])) as $specifier) {
                if ($this->isAllowed($specifier)) {
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
    private function panelImportsIn(string $source): array
    {
        // Strip comments first: a commented-out import is not an import, and a
        // package explaining in prose why it does not use @/lib/http should not
        // be refused for saying so.
        $source = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;
        $source = preg_replace('~//[^\n]*~', '', $source) ?? $source;

        if (preg_match_all(trim(self::PANEL_IMPORT), $source, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[2] ?? []));
    }

    /**
     * `@/extensions-sdk` itself and anything beneath it. Compared on a segment
     * boundary so a sibling directory whose name merely starts the same way —
     * `@/extensions-sdk-internal` — is not admitted.
     */
    private function isAllowed(string $specifier): bool
    {
        return $specifier === self::ALLOWED_PREFIX
            || str_starts_with($specifier, self::ALLOWED_PREFIX . '/');
    }

    private function isFrontendSource(string $path): bool
    {
        return str_starts_with($path, 'frontend/')
            && preg_match('~\.(ts|tsx|js|jsx|mts|mjs)$~', $path) === 1;
    }
}
