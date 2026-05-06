<?php

declare(strict_types=1);

namespace Everest\Services\Extensions;

class ScanResult
{
    public const PASSED  = 'passed';
    public const WARNED  = 'warned';
    public const BLOCKED = 'blocked';

    /**
     * @param array<int, array<string, mixed>> $phpFindings
     * @param array<int, array<string, mixed>> $jsFindings
     * @param array<int, array<string, mixed>> $semgrepFindings
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $phpFindings,
        public readonly array $jsFindings,
        public readonly array $semgrepFindings,
        public readonly string $reportPath,
        public readonly \DateTimeImmutable $scannedAt,
    ) {
    }

    public function isBlocked(): bool
    {
        return $this->outcome === self::BLOCKED;
    }

    public function hasSevereFindings(): bool
    {
        return $this->outcome !== self::PASSED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $high = count(array_filter($this->phpFindings, fn ($f) => ($f['severity'] ?? '') === 'ERROR'))
            + count(array_filter($this->jsFindings, fn ($f) => ($f['severity'] ?? 0) === 2))
            + count(array_filter($this->semgrepFindings, fn ($f) => ($f['severity'] ?? '') === 'ERROR'));

        $warnings = count(array_filter($this->phpFindings, fn ($f) => ($f['severity'] ?? '') === 'WARNING'))
            + count(array_filter($this->jsFindings, fn ($f) => ($f['severity'] ?? 0) === 1))
            + count(array_filter($this->semgrepFindings, fn ($f) => ($f['severity'] ?? '') !== 'ERROR' && ($f['severity'] ?? '') !== ''));

        return [
            'scanned_at'       => $this->scannedAt->format(\DateTimeInterface::ATOM),
            'outcome'          => $this->outcome,
            'php_findings'     => $this->phpFindings,
            'js_findings'      => $this->jsFindings,
            'semgrep_findings' => $this->semgrepFindings,
            'report_path'      => $this->reportPath,
            'summary'          => [
                'high'     => $high,
                'warnings' => $warnings,
            ],
        ];
    }
}
