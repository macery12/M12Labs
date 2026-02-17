import { useEffect, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Input from '@/elements/Input';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import {
    AdminCustomDomain,
    createCustomDomain,
    deleteCustomDomain,
    getCustomDomains,
    updateCustomDomain,
} from '@/api/routes/admin/customDomains';

export default () => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const [loading, setLoading] = useState(false);
    const [domains, setDomains] = useState<AdminCustomDomain[]>([]);
    const [domain, setDomain] = useState('');
    const [zoneId, setZoneId] = useState('');
    const [wildcardEnabled, setWildcardEnabled] = useState(false);

    const loadDomains = async () => {
        const rows = await getCustomDomains();
        setDomains(rows);
    };

    useEffect(() => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        loadDomains()
            .catch(error => clearAndAddHttpError({ key: 'admin:custom-domains', error }))
            .finally(() => setLoading(false));
    }, []);

    const onCreate = async () => {
        if (!domain.trim()) {
            return;
        }

        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await createCustomDomain({
                domain: domain.trim().toLowerCase(),
                cloudflare_zone_id: zoneId.trim() || null,
                wildcard_enabled: wildcardEnabled,
                enabled: true,
            });

            setDomain('');
            setZoneId('');
            setWildcardEnabled(false);

            await loadDomains();
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    const onToggleEnabled = async (row: AdminCustomDomain) => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await updateCustomDomain(row.id, { enabled: !row.enabled });
            await loadDomains();
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    const onDelete = async (id: number) => {
        clearFlashes('admin:custom-domains');
        setLoading(true);

        try {
            await deleteCustomDomain(id);
            await loadDomains();
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:custom-domains', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <SpinnerOverlay visible={loading} />

            <div className={'mb-6 grid grid-cols-1 gap-3 md:grid-cols-5'}>
                <div className={'md:col-span-2'}>
                    <Input
                        value={domain}
                        onChange={e => setDomain(e.currentTarget.value)}
                        placeholder={'example.com'}
                    />
                </div>
                <div className={'md:col-span-2'}>
                    <Input
                        value={zoneId}
                        onChange={e => setZoneId(e.currentTarget.value)}
                        placeholder={'Cloudflare zone ID (optional)'}
                    />
                </div>
                <div className={'flex items-center'}>
                    <Button onClick={onCreate} disabled={!domain.trim()}>
                        Add Domain
                    </Button>
                </div>
            </div>

            <div className={'mb-6'}>
                <label className={'flex items-center gap-2 text-sm text-neutral-300'}>
                    <Input
                        type={'checkbox'}
                        checked={wildcardEnabled}
                        onChange={e => setWildcardEnabled(e.currentTarget.checked)}
                    />
                    Enable wildcard subdomains for new domain
                </label>
            </div>

            <div className={'space-y-2 pb-8'}>
                {domains.length < 1 && (
                    <div className={'rounded border border-neutral-700 bg-neutral-800 p-4 text-sm text-neutral-300'}>
                        No custom domains configured yet.
                    </div>
                )}

                {domains.map(row => (
                    <div
                        key={row.id}
                        className={'flex flex-col gap-3 rounded border border-neutral-700 bg-neutral-800 p-4 md:flex-row md:items-center md:justify-between'}
                    >
                        <div>
                            <div className={'text-sm font-semibold text-neutral-100'}>{row.domain}</div>
                            <div className={'text-xs text-neutral-400'}>
                                Zone: {row.cloudflare_zone_id || 'Auto-resolve'} • Wildcard:{' '}
                                {row.wildcard_enabled ? 'enabled' : 'disabled'}
                            </div>
                        </div>

                        <div className={'flex items-center gap-2'}>
                            <Button
                                color={row.enabled ? 'green' : 'red'}
                                variant={row.enabled ? 'filled' : 'outlined'}
                                onClick={() => onToggleEnabled(row)}
                            >
                                {row.enabled ? 'Enabled' : 'Disabled'}
                            </Button>
                            <Button color={'secondary'} onClick={() => onDelete(row.id)}>
                                Delete
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
};
