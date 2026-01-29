import AdminBox from '@/elements/AdminBox';
import ToggleFeatureButton from '@admin/modules/mods/ToggleFeatureButton';
import { useStoreState } from '@/state/hooks';
import { useEffect, useState } from 'react';
import { useFlashKey } from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { Alert } from '@/elements/alert';
import { getModsAnalytics, ModsAnalytics } from '@/api/routes/admin/mods/settings';
import { ClockIcon, LightningBoltIcon } from '@heroicons/react/outline';

export default () => {
    const [analytics, setAnalytics] = useState<ModsAnalytics | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:mods');
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
                    <Alert type={'warning'}>
                        <strong>No API key configured.</strong> Add your CurseForge API key in Settings to enable mod
                        search functionality.
                    </Alert>
                )}
                <Alert type={'info'}>
                    The Mods module integrates with CurseForge to allow users to search and install Minecraft mods
                    directly from the panel. You'll need a valid CurseForge API key to use this feature.
                </Alert>
                <Alert type={'info'}>
                    <strong>Getting an API key:</strong> Visit the{' '}
                    <a
                        href="https://console.curseforge.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="underline"
                    >
                        CurseForge Console
                    </a>{' '}
                    to request an API key. You'll need to create an organization and then generate an API key for your
                    application.
                </Alert>

                {analytics && (
                    <div className={'grid gap-4 lg:grid-cols-2'}>
                        <AdminBox title={'Rate Limit - This Minute'} icon={LightningBoltIcon}>
                            <div className={'text-center'}>
                                <p className={'text-4xl font-bold'}>
                                    {analytics.rate_limit.requests_this_minute}
                                    <span className={'text-lg text-gray-400'}>
                                        {' '}
                                        / {analytics.rate_limit.limit_per_minute}
                                    </span>
                                </p>
                                <p className={'mt-2 text-sm text-gray-400'}>Requests used this minute</p>
                                {analytics.rate_limit.requests_this_minute >= analytics.rate_limit.limit_per_minute && (
                                    <Alert type={'danger'} className={'mt-2'}>
                                        Rate limit reached! Wait before making more requests.
                                    </Alert>
                                )}
                            </div>
                        </AdminBox>

                        <AdminBox title={'Rate Limit - This Hour'} icon={ClockIcon}>
                            <div className={'text-center'}>
                                <p className={'text-4xl font-bold'}>
                                    {analytics.rate_limit.requests_this_hour}
                                    <span className={'text-lg text-gray-400'}>
                                        {' '}
                                        / {analytics.rate_limit.limit_per_hour}
                                    </span>
                                </p>
                                <p className={'mt-2 text-sm text-gray-400'}>Requests used this hour</p>
                            </div>
                        </AdminBox>
                    </div>
                )}
            </div>

            <div className={'space-y-4'}>
                <Alert type={'warning'}>
                    <strong>Rate Limits:</strong> CurseForge API has strict rate limits. Monitor your usage to avoid
                    exceeding limits.
                </Alert>
                <AdminBox title={'Disable Mods Module'}>
                    Clicking the button below will disable the Mods module for all users. Your CurseForge API key will
                    remain in the database unless you choose to delete it manually.
                    <div className={'mt-2 text-right'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
