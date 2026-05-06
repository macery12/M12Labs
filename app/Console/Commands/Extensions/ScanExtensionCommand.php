<?php

declare(strict_types=1);

namespace Everest\Console\Commands\Extensions;

use Everest\Services\Extensions\ExtensionSecurityScanner;
use Everest\Services\Extensions\ScanResult;
use Illuminate\Console\Command;

class ScanExtensionCommand extends Command
{
    protected $signature = 'extension:scan
                            {path : Path to the .M12LabsExtension file to scan}
                            {--report-only : Run scan but never block installation (useful for CI/auditing)}';

    protected $description = 'Scan a .M12LabsExtension archive for security vulnerabilities.';

    public function __construct(private ExtensionSecurityScanner $scanner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $archivePath = (string) $this->argument('path');

        if (!is_file($archivePath)) {
            $this->components->error(sprintf('File not found: %s', $archivePath));
            return self::FAILURE;
        }

        $this->components->info(sprintf('Scanning extension archive: %s', basename($archivePath)));

        try {
            $result = $this->scanner->scan($archivePath);
        } catch (\Throwable $e) {
            $this->components->error('Scan failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->renderFindings('PHP findings', $result->phpFindings);
        $this->renderFindings('JS/TS findings', $result->jsFindings);
        $this->renderFindings('Semgrep findings', $result->semgrepFindings);

        $summary = $result->toArray()['summary'];
        $this->newLine();
        $this->table(
            ['Outcome', 'High-severity', 'Warnings', 'Report'],
            [[
                strtoupper($result->outcome),
                (string) $summary['high'],
                (string) $summary['warnings'],
                $result->reportPath,
            ]]
        );

        if ($result->isBlocked()) {
            $this->components->error('Installation BLOCKED: high-severity findings detected.');

            if ($this->option('report-only')) {
                $this->components->warn('--report-only flag set; not exiting with failure.');
                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        if ($result->hasSevereFindings()) {
            $this->components->warn('Scan completed with warnings. Review findings before installing.');
        } else {
            $this->components->info('Scan passed — no issues found.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    private function renderFindings(string $label, array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $this->components->info($label . ':');
        $rows = array_map(function (array $f): array {
            $sev = $f['severity'] ?? 'UNKNOWN';
            return [
                is_int($sev) ? ($sev >= 2 ? 'ERROR' : 'WARNING') : (string) $sev,
                basename((string) ($f['file'] ?? '')),
                (string) ($f['line'] ?? 0),
                mb_strimwidth((string) ($f['message'] ?? ''), 0, 80, '…'),
            ];
        }, $findings);

        $this->table(['Severity', 'File', 'Line', 'Message'], $rows);
    }
}
