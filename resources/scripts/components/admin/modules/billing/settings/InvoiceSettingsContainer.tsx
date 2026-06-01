import { useEffect, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import {
    InvoiceSettings,
    StorageUsage,
    getInvoiceSettings,
    updateInvoiceSettings,
    getStorageUsage,
    testStorageConnection,
} from '@/api/routes/admin/billing/invoices';
import Input from '@/elements/Input';
import Label from '@/elements/Label';

const R2_LIMIT_BYTES = 10_200_547_328; // 9.5 GiB

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            onClick={onClick}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
                active
                    ? 'border-b-2 border-blue-500 text-blue-400'
                    : 'text-neutral-400 hover:text-neutral-200'
            }`}
        >
            {children}
        </button>
    );
}

function CompanyTab({ settings, onChange }: { settings: InvoiceSettings; onChange: (k: keyof InvoiceSettings, v: any) => void }) {
    return (
        <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
            {([
                ['company_name', 'Company Name'],
                ['company_address', 'Street Address'],
                ['company_city', 'City'],
                ['company_state', 'State / Province'],
                ['company_zip', 'ZIP / Postal Code'],
                ['company_country', 'Country'],
                ['company_tax_id', 'Tax ID / VAT Number'],
                ['company_logo_url', 'Logo URL'],
                ['invoice_prefix', 'Invoice Prefix (e.g. INV)'],
            ] as [keyof InvoiceSettings, string][]).map(([key, label]) => (
                <div key={key} className='flex flex-col gap-1'>
                    <Label htmlFor={key}>{label}</Label>
                    <Input
                        id={key}
                        value={(settings[key] as string) ?? ''}
                        onChange={e => onChange(key, e.target.value)}
                    />
                </div>
            ))}
        </div>
    );
}

function StorageTab({
    settings,
    usage,
    onChange,
}: {
    settings: InvoiceSettings;
    usage: StorageUsage | null;
    onChange: (k: keyof InvoiceSettings, v: any) => void;
}) {
    const r2Percent = usage ? Math.min(100, (usage.r2_bytes_used / R2_LIMIT_BYTES) * 100) : 0;
    const r2Critical = r2Percent >= 90;
    const [testLoading, setTestLoading] = useState(false);
    const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

    return (
        <div className='flex flex-col gap-6'>
            <div className='flex flex-col gap-1'>
                <Label>Storage Driver</Label>
                <div className='flex flex-wrap gap-3'>
                    {(['local', 's3', 'r2'] as const).map(driver => (
                        <button
                            key={driver}
                            onClick={() => onChange('storage_driver', driver)}
                            className={`flex items-center gap-2 rounded-lg border px-4 py-2 text-sm transition-colors ${
                                settings.storage_driver === driver
                                    ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                                    : 'border-neutral-600 bg-neutral-700 text-neutral-300 hover:border-neutral-500'
                            }`}
                        >
                            {driver.toUpperCase()}
                            {driver === 's3' && (
                                <span className='rounded bg-yellow-600/30 px-1.5 py-0.5 text-xs text-yellow-400'>
                                    Untested
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            </div>

            {settings.storage_driver === 'r2' && (
                <div className='flex flex-col gap-4 rounded-lg border border-neutral-700 bg-neutral-800/50 p-4'>
                    <div className='flex items-start justify-between gap-4'>
                        <h4 className='text-sm font-medium text-neutral-200'>Cloudflare R2 Configuration</h4>
                        <a
                            href='https://dash.cloudflare.com/?to=/:account/r2/overview'
                            target='_blank'
                            rel='noopener noreferrer'
                            className='shrink-0 text-xs text-blue-400 hover:text-blue-300 hover:underline'
                        >
                            Open R2 Dashboard ↗
                        </a>
                    </div>

                    {/* Setup guide */}
                    <div className='rounded-md border border-neutral-600 bg-neutral-900/60 px-4 py-3 text-xs text-neutral-400 leading-relaxed'>
                        <p className='mb-2 font-medium text-neutral-300'>How to get these values:</p>
                        <ol className='list-decimal list-inside space-y-1'>
                            <li><span className='text-neutral-200'>Account ID</span> — found on the R2 overview page in the Cloudflare dashboard (right sidebar).</li>
                            <li><span className='text-neutral-200'>Access Key ID &amp; Secret</span> — create an API token under <em>R2 → Manage R2 API Tokens</em>. Set permissions to <em>Object Read &amp; Write</em> on your bucket.</li>
                            <li><span className='text-neutral-200'>Bucket</span> — the name of the R2 bucket you created (e.g. <code className='text-neutral-300'>my-invoices</code>).</li>
                            <li><span className='text-neutral-200'>Endpoint</span> — auto-filled from your Account ID. Only change this if you use a custom domain on the bucket.</li>
                        </ol>
                    </div>

                    <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
                        {(
                            [
                                {
                                    field: 'account_id',
                                    label: 'Account ID',
                                    placeholder: 'e.g. a1b2c3d4e5f6...',
                                    hint: 'Found on the R2 overview page (right sidebar).',
                                },
                                {
                                    field: 'key',
                                    label: 'Access Key ID',
                                    placeholder: 'e.g. abc123def456...',
                                    hint: 'Generated under R2 → Manage R2 API Tokens.',
                                },
                                {
                                    field: 'secret',
                                    label: 'Secret Access Key',
                                    placeholder: 'Paste your secret key here',
                                    hint: 'Only shown once when the token is created.',
                                },
                                {
                                    field: 'bucket',
                                    label: 'Bucket Name',
                                    placeholder: 'e.g. my-invoices',
                                    hint: 'The name of your R2 bucket.',
                                },
                                {
                                    field: 'endpoint',
                                    label: 'Endpoint URL',
                                    placeholder: 'https://{account_id}.r2.cloudflarestorage.com',
                                    hint: 'Leave blank to auto-generate from Account ID, or use a custom domain.',
                                },
                            ] as { field: string; label: string; placeholder: string; hint: string }[]
                        ).map(({ field, label, placeholder, hint }) => (
                            <div key={field} className='flex flex-col gap-1'>
                                <Label htmlFor={`r2_${field}`}>{label}</Label>
                                <Input
                                    id={`r2_${field}`}
                                    type={field === 'secret' ? 'password' : 'text'}
                                    placeholder={placeholder}
                                    value={settings.storage_config?.[field] === '**redacted**' ? '' : (settings.storage_config?.[field] ?? '')}
                                    onChange={e =>
                                        onChange('storage_config', {
                                            ...(settings.storage_config ?? {}),
                                            [field]: e.target.value,
                                        })
                                    }
                                />
                                <p className='text-xs text-neutral-500'>{hint}</p>
                            </div>
                        ))}
                    </div>

                    {/* R2 Usage bar */}
                    {usage && (
                        <div className='flex flex-col gap-2'>
                            <div className='flex items-center justify-between text-sm'>
                                <span className='text-neutral-400'>Storage Used</span>
                                <span className={r2Critical ? 'text-red-400' : 'text-neutral-300'}>
                                    {formatBytes(usage.r2_bytes_used)} / {formatBytes(R2_LIMIT_BYTES)}
                                    {' '}({usage.r2_percent_used.toFixed(1)}%)
                                </span>
                            </div>
                            <div className='h-2 w-full overflow-hidden rounded-full bg-neutral-700'>
                                <div
                                    className={`h-full rounded-full transition-all ${r2Critical ? 'bg-red-500' : 'bg-blue-500'}`}
                                    style={{ width: `${r2Percent}%` }}
                                />
                            </div>
                            {r2Critical && (
                                <p className='text-xs text-red-400'>
                                    Warning: Storage is above 90%. New invoice PDFs will be blocked at 9.5 GiB.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {settings.storage_driver === 's3' && (
                <div className='flex flex-col gap-4 rounded-lg border border-neutral-700 bg-neutral-800/50 p-4'>
                    <div className='flex items-start justify-between gap-4'>
                        <h4 className='text-sm font-medium text-neutral-200'>AWS S3 Configuration</h4>
                        <a
                            href='https://s3.console.aws.amazon.com/s3/home'
                            target='_blank'
                            rel='noopener noreferrer'
                            className='shrink-0 text-xs text-blue-400 hover:text-blue-300 hover:underline'
                        >
                            Open S3 Console ↗
                        </a>
                    </div>
                    <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
                        {(
                            [
                                {
                                    field: 'key',
                                    label: 'Access Key ID',
                                    placeholder: 'e.g. AKIAIOSFODNN7EXAMPLE',
                                    hint: 'Found under IAM → Users → Security Credentials.',
                                },
                                {
                                    field: 'secret',
                                    label: 'Secret Access Key',
                                    placeholder: 'Paste your secret key here',
                                    hint: 'Only shown once when the IAM key is created.',
                                },
                                {
                                    field: 'region',
                                    label: 'Region',
                                    placeholder: 'e.g. us-east-1',
                                    hint: 'The AWS region your bucket is in (e.g. us-east-1, eu-west-1).',
                                },
                                {
                                    field: 'bucket',
                                    label: 'Bucket Name',
                                    placeholder: 'e.g. my-invoices',
                                    hint: 'The name of your S3 bucket.',
                                },
                            ] as { field: string; label: string; placeholder: string; hint: string }[]
                        ).map(({ field, label, placeholder, hint }) => (
                            <div key={field} className='flex flex-col gap-1'>
                                <Label htmlFor={`s3_${field}`}>{label}</Label>
                                <Input
                                    id={`s3_${field}`}
                                    type={field === 'secret' ? 'password' : 'text'}
                                    placeholder={placeholder}
                                    value={settings.storage_config?.[field] === '**redacted**' ? '' : (settings.storage_config?.[field] ?? '')}
                                    onChange={e =>
                                        onChange('storage_config', {
                                            ...(settings.storage_config ?? {}),
                                            [field]: e.target.value,
                                        })
                                    }
                                />
                                <p className='text-xs text-neutral-500'>{hint}</p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {settings.storage_driver === 'local' && usage?.local_bytes_used != null && (
                <div className='rounded-lg border border-neutral-700 bg-neutral-800/50 p-4'>
                    <p className='text-sm text-neutral-400'>
                        Local storage used by invoices: <span className='text-neutral-200'>{formatBytes(usage.local_bytes_used)}</span>
                    </p>
                </div>
            )}

            {/* Test Connection */}
            <div className='flex items-center gap-4'>
                <button
                    disabled={testLoading}
                    onClick={() => {
                        setTestLoading(true);
                        setTestResult(null);
                        testStorageConnection()
                            .then(res => setTestResult(res))
                            .catch(err => setTestResult({ ok: false, message: err?.response?.data?.message ?? err.message ?? 'Test failed.' }))
                            .finally(() => setTestLoading(false));
                    }}
                    className='rounded bg-neutral-600 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-500 disabled:opacity-50'
                >
                    {testLoading ? 'Testing…' : 'Test Connection'}
                </button>
                {testResult && (
                    <span className={`text-sm font-medium ${testResult.ok ? 'text-green-400' : 'text-red-400'}`}>
                        {testResult.ok ? '✓ ' : '✗ '}{testResult.message}
                    </span>
                )}
            </div>
        </div>
    );
}

function RetentionTab({ settings, onChange }: { settings: InvoiceSettings; onChange: (k: keyof InvoiceSettings, v: any) => void }) {
    return (
        <div className='flex flex-col gap-4 max-w-sm'>
            <div className='flex flex-col gap-1'>
                <Label htmlFor='auto_cleanup_enabled'>Auto-Cleanup</Label>
                <div className='flex items-center gap-3'>
                    <input
                        id='auto_cleanup_enabled'
                        type='checkbox'
                        checked={settings.auto_cleanup_enabled}
                        onChange={e => onChange('auto_cleanup_enabled', e.target.checked)}
                        className='h-4 w-4 rounded border-neutral-600 bg-neutral-700 text-blue-500'
                    />
                    <span className='text-sm text-neutral-300'>Enable automatic data cleanup</span>
                </div>
                <p className='text-xs text-neutral-400'>
                    Invoice data is stored forever by default. Enable this to delete snapshots older than a set number of years.
                </p>
            </div>
            {settings.auto_cleanup_enabled && (
                <div className='flex flex-col gap-1'>
                    <Label htmlFor='auto_cleanup_after_years'>Delete data after (years)</Label>
                    <Input
                        id='auto_cleanup_after_years'
                        type='number'
                        min={1}
                        max={100}
                        value={settings.auto_cleanup_after_years}
                        onChange={e => onChange('auto_cleanup_after_years', parseInt(e.target.value, 10))}
                    />
                    <p className='text-xs text-neutral-400'>
                        Encrypted data snapshots older than this will be deleted. PDF cache is separate (24 h TTL always).
                    </p>
                </div>
            )}
            <div className='flex flex-col gap-1 border-t border-neutral-700 pt-4'>
                <Label htmlFor='require_billing_address'>Customer Requirements</Label>
                <div className='flex items-center gap-3'>
                    <input
                        id='require_billing_address'
                        type='checkbox'
                        checked={settings.require_billing_address ?? false}
                        onChange={e => onChange('require_billing_address', e.target.checked)}
                        className='h-4 w-4 rounded border-neutral-600 bg-neutral-700 text-blue-500'
                    />
                    <span className='text-sm text-neutral-300'>Require billing address to checkout</span>
                </div>
                <p className='text-xs text-neutral-400'>
                    When enabled, customers must add a billing address in their account settings before they can complete a purchase. The address is included on all invoices.
                </p>
            </div>
            <div className='rounded-lg border border-neutral-700 bg-neutral-800/50 p-4'>
                <p className='text-sm text-neutral-400'>
                    Current sequence: <span className='font-mono text-neutral-200'>#{settings.invoice_sequence}</span>
                </p>
                <p className='mt-1 text-xs text-neutral-500'>Invoice numbering is global and never resets per year.</p>
            </div>
        </div>
    );
}

export default () => {
    const { addFlash, clearFlashes } = useFlash();
    const [settings, setSettings] = useState<InvoiceSettings | null>(null);
    const [usage, setUsage] = useState<StorageUsage | null>(null);
    const [tab, setTab] = useState<'company' | 'storage' | 'retention'>('company');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        getInvoiceSettings().then(setSettings).catch(() =>
            addFlash({ key: 'admin:invoice-settings', type: 'error', message: 'Failed to load invoice settings.' })
        );
        getStorageUsage().then(setUsage).catch(() => {});
    }, []);

    const handleChange = (key: keyof InvoiceSettings, value: any) => {
        setSettings(prev => prev ? { ...prev, [key]: value } : prev);
    };

    const handleSave = () => {
        if (!settings) return;
        setSaving(true);
        clearFlashes('admin:invoice-settings');
        updateInvoiceSettings(settings)
            .then(updated => {
                setSettings(updated);
                addFlash({ key: 'admin:invoice-settings', type: 'success', message: 'Invoice settings saved.' });
            })
            .catch(err => addFlash({ key: 'admin:invoice-settings', type: 'error', message: err.message ?? 'Save failed.' }))
            .finally(() => setSaving(false));
    };

    if (!settings) {
        return <p className='text-sm text-neutral-400'>Loading settings...</p>;
    }

    return (
        <div className='flex flex-col gap-6'>
            <div>
                <h2 className='font-header text-xl font-medium text-neutral-50'>Invoice Settings</h2>
                <p className='text-sm text-neutral-400'>Configure company details, storage driver, and retention policy for invoices.</p>
            </div>

            <div className='flex border-b border-neutral-700'>
                <TabButton active={tab === 'company'} onClick={() => setTab('company')}>Company Info</TabButton>
                <TabButton active={tab === 'storage'} onClick={() => setTab('storage')}>Storage</TabButton>
                <TabButton active={tab === 'retention'} onClick={() => setTab('retention')}>Retention</TabButton>
            </div>

            <div>
                {tab === 'company' && <CompanyTab settings={settings} onChange={handleChange} />}
                {tab === 'storage' && <StorageTab settings={settings} usage={usage} onChange={handleChange} />}
                {tab === 'retention' && <RetentionTab settings={settings} onChange={handleChange} />}
            </div>

            <div className='flex justify-end'>
                <button
                    disabled={saving}
                    onClick={handleSave}
                    className='rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50'
                >
                    {saving ? 'Saving...' : 'Save Changes'}
                </button>
            </div>
        </div>
    );
};
