<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\Service\Extension\ForeignExtensionIdException;

/**
 * Which package is calling, read from where the call came from rather than from
 * what the call claims.
 *
 * The SDK's `::for($extensionId)` facades take the id as an argument because
 * that is the only ergonomic shape PHP offers, and a package passes its own id
 * as a literal, a class constant or a config value — none of which a source
 * scanner can resolve. So the check happens at the call: walk the stack to the
 * nearest frame executing package code, and that package is the caller.
 *
 * "Nearest", on purpose. A package reached through core — a hook core
 * dispatches, a route core registered — has core frames and package frames on
 * its stack, and the innermost package is the one doing the asking. A package
 * that tries to launder the call through a core or vendor function
 * (`app()->call([PackageSecrets::class, 'for'], ...)`) still leaves its own
 * frame nearer than any other package's. A call with no package code on the
 * stack at all is core's own, or a test, and is let through.
 *
 * Like everything else about package PHP, this is not a sandbox: code that is
 * determined to misbehave has the whole language. It turns the cross-package
 * shortcut from a one-word change into something a reviewer would notice.
 */
class ExtensionCallerGuard
{
    /**
     * @throws ForeignExtensionIdException
     */
    public static function assertCallerIs(string $extensionId): void
    {
        $caller = self::callingExtension();

        if ($caller !== null && $caller !== $extensionId) {
            throw new ForeignExtensionIdException($caller, $extensionId);
        }
    }

    /**
     * The extension whose code is nearest on the stack, or null when none is.
     */
    public static function callingExtension(): ?string
    {
        $roots = self::packageRoots();

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }

            $file = str_replace('\\', '/', $file);

            foreach ($roots as $root) {
                if (!str_starts_with($file, $root)) {
                    continue;
                }

                $id = strstr(substr($file, strlen($root)), '/', true);

                return is_string($id) && $id !== '' ? $id : null;
            }
        }

        return null;
    }

    /**
     * The package directory as configured, and as the filesystem resolves it,
     * since a frame reports whichever path PHP opened the file through.
     *
     * @return array<int, string>
     */
    private static function packageRoots(): array
    {
        $configured = rtrim(str_replace('\\', '/', base_path('app/Extensions/Packages')), '/') . '/';
        $resolved = realpath($configured);

        return array_values(array_unique(array_filter([
            $configured,
            $resolved === false ? null : rtrim(str_replace('\\', '/', $resolved), '/') . '/',
        ])));
    }
}
