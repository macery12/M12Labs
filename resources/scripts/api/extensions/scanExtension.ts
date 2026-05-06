import http from '@/api/http';

export interface ScanFinding {
    file: string;
    line: number;
    column?: number;
    severity: string | number;
    message: string;
    source?: string;
    rule?: string;
}

export interface ScanSummary {
    high: number;
    warnings: number;
}

export interface ScanReport {
    scanned_at: string;
    outcome: 'passed' | 'warned' | 'blocked';
    php_findings: ScanFinding[];
    js_findings: ScanFinding[];
    semgrep_findings: ScanFinding[];
    report_path?: string;
    summary: ScanSummary;
}

/**
 * Upload a .M12LabsExtension file and run the security scanner against it.
 * Resolves with the scan report on success (passed/warned).
 * Rejects (with the scan report attached to the error) when the scan is blocked.
 */
export const scanExtension = async (file: File): Promise<ScanReport> => {
    const form = new FormData();
    form.append('extension_file', file);

    const { data } = await http.post<ScanReport>('/api/client/extensions/scan', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 120_000,
    });

    return data;
};

/**
 * Fetch the stored scan report for an already-installed extension by its slug.
 */
export const getScanReport = async (slug: string): Promise<ScanReport> => {
    const { data } = await http.get<ScanReport>(`/api/client/extensions/${encodeURIComponent(slug)}/scan-report`);
    return data;
};
