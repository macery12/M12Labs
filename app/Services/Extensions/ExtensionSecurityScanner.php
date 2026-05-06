<?php

declare(strict_types=1);

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class ExtensionSecurityScanner
{
    private const MANIFEST_FILENAME = 'm12labs-extension.json';

    /** Maximum number of entries allowed in an extension archive (zip-bomb guard). */
    private const MAX_ZIP_ENTRIES = 10_000;

    /** Maximum total uncompressed size allowed in an extension archive (zip-bomb guard), in bytes. */
    private const MAX_EXTRACTED_BYTES = 500 * 1024 * 1024;

    public function scan(string $archivePath): ScanResult
    {
        $uuid    = Str::uuid()->toString();
        $tempDir = rtrim(config('extensions.scan.temp_dir', storage_path('app/extension-scans')), '/') . '/' . $uuid;

        File::ensureDirectoryExists($tempDir);

        try {
            // Step 1 — Extract archive
            $this->extractArchive($archivePath, $tempDir);

            // Step 2 — Validate manifest
            $slug = $this->validateManifest($tempDir, $archivePath);

            // Step 3 — PHP scan
            $phpFindings = $this->runPhpScan($tempDir);

            // Step 4 — JS/TS scan
            $jsFindings = $this->runJsScan($tempDir);

            // Step 5 — Semgrep scan (optional)
            $semgrepFindings = $this->runSemgrepScan($tempDir);

            // Step 6 — Decide outcome
            $highCount = $this->countHigh($phpFindings, $jsFindings, $semgrepFindings);
            $warnCount = $this->countWarnings($phpFindings, $jsFindings, $semgrepFindings);
            $blockOnHigh = (bool) config('extensions.scan.block_on_high', true);

            $outcome = match (true) {
                $highCount > 0 && $blockOnHigh => ScanResult::BLOCKED,
                $highCount > 0 || $warnCount > 0 => ScanResult::WARNED,
                default                          => ScanResult::PASSED,
            };

            // Step 7 — Write report
            $reportPath = $this->writeReport($slug, $outcome, $phpFindings, $jsFindings, $semgrepFindings);

            return new ScanResult(
                outcome: $outcome,
                phpFindings: $phpFindings,
                jsFindings: $jsFindings,
                semgrepFindings: $semgrepFindings,
                reportPath: $reportPath,
                scannedAt: new \DateTimeImmutable(),
            );
        } finally {
            // Step 8 — Cleanup
            File::deleteDirectory($tempDir);
        }
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    private function extractArchive(string $archivePath, string $targetDir): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new \RuntimeException(sprintf(
                'Could not open extension archive "%s" (ZipArchive error %d).',
                basename($archivePath),
                $result
            ));
        }

        $realTarget = realpath($targetDir);
        if ($realTarget === false) {
            $zip->close();
            throw new \RuntimeException('Could not resolve extraction target directory.');
        }

        $totalEntries = $zip->count();

        // Zip-bomb guard: reject archives with an excessive number of entries.
        if ($totalEntries > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw new \RuntimeException(sprintf(
                'Extension archive contains too many entries (%d). Maximum allowed is %d.',
                $totalEntries,
                self::MAX_ZIP_ENTRIES
            ));
        }

        $totalUncompressedSize = 0;

        for ($i = 0; $i < $totalEntries; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }

            // Zip-slip guard: normalise the entry path purely by string manipulation so
            // that the check works even before any file exists on disk (realpath() returns
            // false for non-existent paths and would silently skip the traversal check).
            $rawPath    = $realTarget . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);
            $segments   = explode(DIRECTORY_SEPARATOR, $rawPath);
            $normalised = [];
            foreach ($segments as $segment) {
                if ($segment === '..') {
                    if (empty($normalised)) {
                        // More '..' segments than valid components — path escapes the root.
                        $zip->close();
                        throw new \RuntimeException(sprintf(
                            'Extension archive contains a path traversal entry: "%s". Aborting extraction.',
                            $entry
                        ));
                    }
                    array_pop($normalised);
                } elseif ($segment !== '.') {
                    $normalised[] = $segment;
                }
            }
            $resolvedPath = implode(DIRECTORY_SEPARATOR, $normalised);

            if (!str_starts_with($resolvedPath, $realTarget . DIRECTORY_SEPARATOR)) {
                $zip->close();
                throw new \RuntimeException(sprintf(
                    'Extension archive contains a path traversal entry: "%s". Aborting extraction.',
                    $entry
                ));
            }

            // Zip-bomb guard: accumulate uncompressed sizes and reject if the limit is exceeded.
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $totalUncompressedSize += $stat['size'];
                if ($totalUncompressedSize > self::MAX_EXTRACTED_BYTES) {
                    $zip->close();
                    throw new \RuntimeException(sprintf(
                        'Extension archive exceeds the maximum allowed extracted size (%d bytes).',
                        self::MAX_EXTRACTED_BYTES
                    ));
                }
            }
        }

        $zip->extractTo($realTarget);
        $zip->close();
    }

    // -------------------------------------------------------------------------
    // Manifest validation
    // -------------------------------------------------------------------------

    private function validateManifest(string $tempDir, string $archivePath): string
    {
        $manifestPath = $tempDir . '/' . self::MANIFEST_FILENAME;

        if (!is_file($manifestPath)) {
            throw new \RuntimeException(sprintf(
                'Extension archive "%s" is missing the required %s manifest.',
                basename($archivePath),
                self::MANIFEST_FILENAME
            ));
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new \RuntimeException('Could not read extension manifest.');
        }

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Extension manifest contains malformed JSON.', 0, $e);
        }

        $id      = trim((string) ($manifest['extension']['id']     ?? ''));
        $version = trim((string) ($manifest['package']['version']  ?? ''));
        $author  = trim((string) ($manifest['extension']['author'] ?? ''));
        $name    = trim((string) ($manifest['extension']['name']   ?? ''));

        if ($id === '' || $version === '' || ($author === '' && $name === '')) {
            throw new \RuntimeException(
                'Extension manifest must contain at minimum: extension.id, package.version, and extension.name or extension.author.'
            );
        }

        return $id;
    }

    // -------------------------------------------------------------------------
    // PHP scan (phpcs)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runPhpScan(string $dir): array
    {
        $binary = (string) config('extensions.scan.phpcs_binary', 'phpcs');

        if (!$this->binaryExists($binary)) {
            Log::warning('ExtensionSecurityScanner: phpcs binary not found, skipping PHP scan.', ['binary' => $binary]);
            return [];
        }

        $phpFiles = $this->findFiles($dir, ['php']);
        if ($phpFiles === []) {
            return [];
        }

        $process = new Process([
            $binary,
            '--standard=Security',
            '--report=json',
            '--severity=1',
            ...$phpFiles,
        ]);
        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput();
        if (empty($output)) {
            return [];
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $findings = [];
        foreach ((array) ($decoded['files'] ?? []) as $filePath => $fileData) {
            foreach ((array) ($fileData['messages'] ?? []) as $message) {
                $findings[] = [
                    'file'     => $filePath,
                    'line'     => $message['line'] ?? 0,
                    'column'   => $message['column'] ?? 0,
                    'severity' => strtoupper((string) ($message['type'] ?? 'WARNING')),
                    'message'  => $message['message'] ?? '',
                    'source'   => $message['source'] ?? '',
                ];
            }
        }

        return $findings;
    }

    // -------------------------------------------------------------------------
    // JS/TS scan (ESLint)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runJsScan(string $dir): array
    {
        $jsFiles = $this->findFiles($dir, ['ts', 'tsx', 'js']);
        if ($jsFiles === []) {
            return [];
        }

        $binaryParts = explode(' ', (string) config('extensions.scan.eslint_binary', 'npx eslint'));

        if (!$this->binaryExists($binaryParts[0])) {
            Log::warning('ExtensionSecurityScanner: eslint binary not found, skipping JS scan.', ['binary' => $binaryParts[0]]);
            return [];
        }

        $eslintMajor = $this->getEslintMajorVersion($binaryParts[0]);

        File::ensureDirectoryExists(storage_path('app/tmp'));

        if ($eslintMajor >= 9) {
            // ESLint v9+ uses flat config (eslint.config.cjs). The legacy eslintrc
            // format and --no-eslintrc flag were removed in v9.
            // We write the config to storage/app/tmp/ but ESLint's base path is
            // the process CWD — files outside of it are silently ignored. We
            // therefore run ESLint with CWD = $dir and pass relative file paths.
            $eslintConfigPath = storage_path('app/tmp/.eslint-scan-' . Str::uuid()->toString() . '.cjs');
            $pluginPath = $this->resolveNodeModule('eslint-plugin-security', $binaryParts[0]);

            if ($pluginPath === null) {
                Log::warning('ExtensionSecurityScanner: eslint-plugin-security not found, skipping JS scan.');
                return [];
            }

            // All security rules are set to 'error' so they count as high-severity
            // findings and can trigger BLOCKED, consistent with phpcs behaviour.
            $configContent = <<<JSEOF
const security = require({$this->jsString($pluginPath)});
const rules = Object.fromEntries(
  Object.keys(security.configs.recommended.rules).map(k => [k, 'error'])
);
module.exports = [{plugins:{security}, rules, languageOptions:{ecmaVersion:2020}}];
JSEOF;
            File::put($eslintConfigPath, $configContent);

            // Build relative paths so they fall within ESLint's base path (CWD = $dir).
            $relativeFiles = array_map(
                fn (string $f) => ltrim(substr($f, strlen(rtrim($dir, '/'))), '/'),
                $jsFiles
            );

            $cmd = array_merge(
                $binaryParts,
                ['-c', $eslintConfigPath, '--no-warn-ignored', '--format', 'json'],
                $relativeFiles
            );

            $process = new Process($cmd);
            $process->setWorkingDirectory($dir);
        } else {
            // ESLint ≤ v8: legacy eslintrc JSON config.
            $eslintConfigPath = storage_path('app/tmp/.eslint-scan-' . Str::uuid()->toString() . '.json');
            $eslintConfig = [
                'plugins' => ['security'],
                'rules'   => ['security/detect-object-injection' => 'error'],
                'extends' => ['plugin:security/recommended'],
            ];

            $encoded = json_encode($eslintConfig);
            if ($encoded === false) {
                throw new \RuntimeException('Failed to encode ESLint config as JSON: ' . json_last_error_msg());
            }

            File::put($eslintConfigPath, $encoded);

            $cmd = array_merge(
                $binaryParts,
                ['--no-eslintrc', '-c', $eslintConfigPath, '--format', 'json'],
                $jsFiles
            );

            $process = new Process($cmd);
        }

        $process->setTimeout(120);

        try {
            $process->run();

            $output = $process->getOutput();
            if (empty($output)) {
                return [];
            }

            try {
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            $findings = [];
            foreach ((array) $decoded as $fileResult) {
                foreach ((array) ($fileResult['messages'] ?? []) as $msg) {
                    $sev = (int) ($msg['severity'] ?? 1);
                    $findings[] = [
                        'file'     => $fileResult['filePath'] ?? '',
                        'line'     => $msg['line'] ?? 0,
                        'column'   => $msg['column'] ?? 0,
                        'severity' => $sev,
                        'message'  => $msg['message'] ?? '',
                        'rule'     => $msg['ruleId'] ?? '',
                    ];
                }
            }

            return $findings;
        } finally {
            @unlink($eslintConfigPath);
        }
    }

    /**
     * Returns the major version number of the configured ESLint binary, or 0 on failure.
     */
    private function getEslintMajorVersion(string $binary): int
    {
        $process = new Process([$binary, '--version']);
        $process->setTimeout(10);
        $process->run();
        // Output is like "v10.3.0\n"
        if (preg_match('/v?(\d+)/', trim($process->getOutput()), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Resolves the absolute directory path for a Node module, searching relative
     * to the eslint binary's own node_modules first, then falling back to the
     * global npm root.
     */
    private function resolveNodeModule(string $module, string $eslintBinary): ?string
    {
        $eslintCmd   = explode(' ', $eslintBinary)[0];
        $eslintFound = (new ExecutableFinder())->find($eslintCmd);

        // If the binary is an absolute path it won't be found via PATH search; use it directly.
        if ($eslintFound === null && (str_starts_with($eslintCmd, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $eslintCmd))) {
            $eslintFound = $eslintCmd;
        }

        $candidates = [];
        if ($eslintFound !== null) {
            $eslintDir   = dirname($eslintFound);
            $candidates[] = $eslintDir . '/../lib/node_modules/' . $module;
            $candidates[] = $eslintDir . '/../../' . $module;
        }

        // Global npm root as final fallback
        $npmRoot = trim((string) shell_exec('npm root -g 2>/dev/null'));
        if ($npmRoot !== '') {
            $candidates[] = $npmRoot . '/' . $module;
        }

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: null;
            }
        }

        return null;
    }

    /**
     * Returns a JS string literal (double-quoted, with backslashes and double-quotes escaped).
     */
    private function jsString(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }

    // -------------------------------------------------------------------------
    // Semgrep scan (optional)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runSemgrepScan(string $dir): array
    {
        if (!config('extensions.scan.semgrep_enabled', false)) {
            return [];
        }

        $binary = (string) config('extensions.scan.semgrep_binary', 'semgrep');

        if (!$this->binaryExists($binary)) {
            Log::info('ExtensionSecurityScanner: semgrep binary not found, skipping.', ['binary' => $binary]);
            return [];
        }

        $process = new Process([
            $binary,
            ...array_map(
                fn (string $r) => '--config=' . $r,
                array_filter(array_map('trim', explode(',', (string) config('extensions.scan.semgrep_rulesets', 'p/php-security,p/javascript'))))
            ),
            '--json',
            $dir,
        ]);
        $process->setTimeout(180);
        $process->run();

        $output = $process->getOutput();
        if (empty($output)) {
            return [];
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $findings = [];
        foreach ((array) ($decoded['results'] ?? []) as $result) {
            $findings[] = [
                'file'     => $result['path'] ?? '',
                'line'     => $result['start']['line'] ?? 0,
                'severity' => strtoupper((string) ($result['extra']['severity'] ?? 'WARNING')),
                'message'  => $result['extra']['message'] ?? '',
                'rule'     => $result['check_id'] ?? '',
            ];
        }

        return $findings;
    }

    // -------------------------------------------------------------------------
    // Report
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $phpFindings
     * @param array<int, array<string, mixed>> $jsFindings
     * @param array<int, array<string, mixed>> $semgrepFindings
     */
    private function writeReport(
        string $slug,
        string $outcome,
        array $phpFindings,
        array $jsFindings,
        array $semgrepFindings,
    ): string {
        // BLOCKED extensions are never installed, so writing the report into the
        // install directory would leave orphaned files. We route blocked reports to
        // a dedicated "blocked-scans" directory for audit purposes while keeping
        // the installed directory clean.
        if ($outcome === ScanResult::BLOCKED) {
            $reportDir = rtrim(config('extensions.scan.install_dir', storage_path('app/extensions/installed')), '/');
            $reportDir = dirname($reportDir) . '/blocked-scans/' . $slug;
        } else {
            $reportDir = rtrim(config('extensions.scan.install_dir', storage_path('app/extensions/installed')), '/') . '/' . $slug;
        }

        File::ensureDirectoryExists($reportDir);

        $reportPath = $reportDir . '/scan-report.json';

        $high = $this->countHigh($phpFindings, $jsFindings, $semgrepFindings);
        $warn = $this->countWarnings($phpFindings, $jsFindings, $semgrepFindings);

        $report = [
            'scanned_at'       => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'outcome'          => $outcome,
            'php_findings'     => $phpFindings,
            'js_findings'      => $jsFindings,
            'semgrep_findings' => $semgrepFindings,
            'summary'          => [
                'high'     => $high,
                'warnings' => $warn,
            ],
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));

        return $reportPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string> $extensions
     * @return array<int, string>
     */
    private function findFiles(string $dir, array $extensions): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function binaryExists(string $binary): bool
    {
        // For compound commands like "npx eslint", check only the first token.
        $cmd = explode(' ', $binary)[0];

        // If an absolute path was provided, check directly rather than searching PATH,
        // because ExecutableFinder::find() only resolves bare command names via PATH.
        // Covers Unix (/path/to/bin) and Windows (C:\path\to\bin or C:/path/to/bin).
        if (str_starts_with($cmd, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $cmd)) {
            return is_file($cmd) && is_executable($cmd);
        }

        $finder = new ExecutableFinder();

        return $finder->find($cmd) !== null;
    }

    /**
     * @param array<int, array<string, mixed>> $phpFindings
     * @param array<int, array<string, mixed>> $jsFindings
     * @param array<int, array<string, mixed>> $semgrepFindings
     */
    private function countHigh(array $phpFindings, array $jsFindings, array $semgrepFindings): int
    {
        return count(array_filter($phpFindings, fn ($f) => ($f['severity'] ?? '') === 'ERROR'))
            + count(array_filter($jsFindings, fn ($f) => ($f['severity'] ?? 0) === 2))
            + count(array_filter($semgrepFindings, fn ($f) => ($f['severity'] ?? '') === 'ERROR'));
    }

    /**
     * @param array<int, array<string, mixed>> $phpFindings
     * @param array<int, array<string, mixed>> $jsFindings
     * @param array<int, array<string, mixed>> $semgrepFindings
     */
    private function countWarnings(array $phpFindings, array $jsFindings, array $semgrepFindings): int
    {
        return count(array_filter($phpFindings, fn ($f) => ($f['severity'] ?? '') === 'WARNING'))
            + count(array_filter($jsFindings, fn ($f) => ($f['severity'] ?? 0) === 1))
            + count(array_filter($semgrepFindings, fn ($f) => ($f['severity'] ?? '') === 'WARNING'));
    }
}
