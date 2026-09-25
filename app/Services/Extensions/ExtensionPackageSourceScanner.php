<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\Log;

/**
 * Applies every source-level package gate to the final, checksum-verified file
 * plans. Keeping the gates together prevents one lifecycle path from applying
 * only the frontend half while admitting PHP another path would reject.
 */
class ExtensionPackageSourceScanner
{
    public function __construct(
        private ExtensionFrontendImportScanner $frontendScanner,
        private ExtensionPhpSourceScanner $phpScanner,
    ) {
    }

    /**
     * @param array<int, array{path: string, sourcePath: string}> $filePlans
     * @param array<string, string> $declaredNpmPackages
     */
    public function assertSafe(string $extensionId, array $filePlans, array $declaredNpmPackages = []): void
    {
        $this->frontendScanner->assertOnlySdkImports($filePlans, $declaredNpmPackages);

        // PHP advisories are judgement calls a reviewer owns. Blocking
        // findings throw before this method returns; advisories remain visible
        // without turning the scanner into a false promise of sandboxing.
        foreach ($this->phpScanner->assertSafe($extensionId, $filePlans) as $advisory) {
            Log::notice('Extension source advisory.', ['extension' => $extensionId, 'finding' => $advisory]);
        }
    }
}
