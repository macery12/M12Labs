import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/mods/ToggleFeatureButton';
import { useStoreState } from '@/state/hooks';
import { useEffect, useState } from 'react';
import { useFlashKey } from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';
import { getModsAnalytics, ModsAnalytics } from '@/api/routes/admin/mods/settings';
import { LightningBoltIcon, ChartSquareBarIcon, DatabaseIcon } from '@heroicons/react/outline';

export default () => {
    const [analytics, setAnalytics] = useState<ModsAnalytics | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:plugins');
    const hasApiKey = useStoreState(s => s.everest.data!.mods.curseforge_api_key);

    useEffect(() => {
        clearFlashes();
        getModsAnalytics()
            .then(data => {
                setAnalytics(data);
                setLoading(false);
            })
            .catch(error => {
                clearAndAddHttpError(error);
                setLoading(false);
            });
    }, []);

    if (loading) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <div className={'grid gap-4 lg:grid-cols-3'}>
            <div className={'col-span-2 space-y-4'}>
                {!hasApiKey && (
                    <Alert type={'info'}>
                        <strong>No CurseForge API key configured.</strong> Modrinth works by default. Add a CurseForge
                        API key in Settings to enable CurseForge content.
                    </Alert>
                )}
                {analytics && (
                    <div className={'grid gap-4 lg:grid-cols-2'}>
                        <AdminBox title={'Total installs'} icon={DatabaseIcon}>
                            <div className={'text-4xl font-bold'}>{analytics.totals.installs}</div>
                            <p className={'text-sm text-neutral-400'}>Across all providers</p>
                        </AdminBox>
                        <AdminBox title={'Failures / Retries'} icon={LightningBoltIcon}>
                            <div className={'text-2xl font-bold'}>
                                {analytics.totals.failures} / {analytics.totals.retries}
                            </div>
                            <p className={'text-sm text-neutral-400'}>Failure and retry counts</p>
                        </AdminBox>
                        <AdminBox title={'Installs by provider'} icon={ChartSquareBarIcon}>
                            <p className={'text-sm text-neutral-300'}>Modrinth: {analytics.totals.by_provider.modrinth}</p>
                            <p className={'text-sm text-neutral-300'}>CurseForge: {analytics.totals.by_provider.curseforge}</p>
                            <p className={'text-sm text-neutral-300'}>Spigot: {analytics.totals.by_provider.spigot}</p>
                        </AdminBox>
                        <AdminBox title={'Bandwidth'} icon={ChartSquareBarIcon}>
                            <p className={'text-lg font-bold'}>{analytics.totals.bandwidth_bytes} bytes</p>
                            <p className={'text-sm text-neutral-400'}>Last 24h: {analytics.totals.bandwidth_bytes_24h} bytes</p>
                        </AdminBox>
                    </div>
                )}
                {analytics && (
                    <div className={'space-y-3'}>
                        <div className={'font-semibold text-neutral-200'}>Provider health</div>
                        <div className={'grid gap-3 lg:grid-cols-3'}>
                            {Object.entries(analytics.provider_health).map(([key, value]) => (
                                <AdminBox key={key} title={key} icon={LightningBoltIcon}>
                                    <p className={'text-sm text-neutral-300'}>Enabled: {value.enabled ? 'Yes' : 'No'}</p>
                                    {value.rate_limit && (
                                        <p className={'text-sm text-neutral-300'}>
                                            {value.rate_limit.requests_this_minute}/{value.rate_limit.limit_per_minute} this minute
                                        </p>
                                    )}
                                    <p className={'text-sm text-neutral-300'}>Denied by policy: {value.denied_by_policy}</p>
                                </AdminBox>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div className={'space-y-4'}>
                <Alert type={'warning'}>
                    Default policy denies all providers unless enabled globally and allowed for nests/eggs. Configure
                    access under Access Control.
                </Alert>
                <AdminBox title={'Disable Marketplace Module'}>
                    Clicking the button below will disable the Marketplace module for all users. Your CurseForge API key will
                    remain in the database unless you choose to delete it manually.
                    <div className={'mt-2 text-right'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
