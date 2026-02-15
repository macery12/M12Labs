import { useEffect, useState } from 'react';
import { httpGet, httpPut } from '@/api/http';
import { useFlash } from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Spinner from '@/elements/Spinner';
import Label from '@/elements/Label';

interface EmailNotificationSetting {
    id: number;
    template_key: string;
    enabled: boolean;
    category: string;
    name: string;
    description: string | null;
    rate_limit_exempt: boolean;
}

interface NotificationSettingsResponse {
    global_enabled: boolean;
    categories: Record<string, EmailNotificationSetting[]>;
}

export default () => {
    const [loading, setLoading] = useState(true);
    const [globalEnabled, setGlobalEnabled] = useState(true);
    const [categories, setCategories] = useState<Record<string, EmailNotificationSetting[]>>({});
    const [toggling, setToggling] = useState<Record<string, boolean>>({});
    const { clearFlashes, addFlash } = useFlash();

    useEffect(() => {
        loadSettings();
    }, []);

    const loadSettings = async () => {
        setLoading(true);
        try {
            const data = await httpGet<NotificationSettingsResponse>('/api/application/email/notifications');
            setGlobalEnabled(data.global_enabled);
            setCategories(data.categories);
        } catch (error: any) {
            addFlash({
                key: 'email:notifications',
                type: 'error',
                message: error.message || 'Failed to load notification settings',
            });
        } finally {
            setLoading(false);
        }
    };

    const toggleGlobal = async () => {
        clearFlashes('email:notifications');
        setToggling({ ...toggling, global: true });

        try {
            await httpPut('/api/application/email/notifications/global', {
                enabled: !globalEnabled,
            });

            setGlobalEnabled(!globalEnabled);
            addFlash({
                key: 'email:notifications',
                type: 'success',
                message: `Email notifications ${!globalEnabled ? 'enabled' : 'disabled'} globally`,
            });
        } catch (error: any) {
            addFlash({
                key: 'email:notifications',
                type: 'error',
                message: error.message || 'Failed to update global setting',
            });
        } finally {
            setToggling({ ...toggling, global: false });
        }
    };

    const toggleSetting = async (setting: EmailNotificationSetting) => {
        clearFlashes('email:notifications');
        setToggling({ ...toggling, [setting.template_key]: true });

        try {
            await httpPut(`/api/application/email/notifications/${setting.id}`, {
                enabled: !setting.enabled,
            });

            // Update local state
            const updatedCategories = { ...categories };
            const category = updatedCategories[setting.category];
            const index = category.findIndex((s) => s.id === setting.id);
            category[index].enabled = !setting.enabled;
            setCategories(updatedCategories);

            addFlash({
                key: 'email:notifications',
                type: 'success',
                message: `${setting.name} ${!setting.enabled ? 'enabled' : 'disabled'}`,
            });
        } catch (error: any) {
            addFlash({
                key: 'email:notifications',
                type: 'error',
                message: error.message || 'Failed to update setting',
            });
        } finally {
            setToggling({ ...toggling, [setting.template_key]: false });
        }
    };

    if (loading) {
        return (
            <div className='flex items-center justify-center py-8'>
                <Spinner size='large' />
            </div>
        );
    }

    const categoryTitles: Record<string, string> = {
        auth: 'Authentication & Security',
        server: 'Server Notifications',
        billing: 'Billing & Payments',
    };

    return (
        <div className='space-y-6'>
            {/* Global Toggle */}
            <div className='border-b border-neutral-700 pb-4'>
                <div className='flex items-center justify-between'>
                    <div>
                        <h3 className='text-lg font-medium'>Global Email Notifications</h3>
                        <p className='text-sm text-neutral-400 mt-1'>
                            Master switch to enable or disable all email notifications
                        </p>
                    </div>
                    <Button
                        onClick={toggleGlobal}
                        disabled={toggling.global}
                        className={`px-4 py-2 ${
                            globalEnabled ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'
                        }`}
                    >
                        {toggling.global ? <Spinner size='small' /> : globalEnabled ? 'Enabled' : 'Disabled'}
                    </Button>
                </div>
            </div>

            {/* Category Groups */}
            {Object.entries(categories).map(([categoryKey, settings]) => (
                <div key={categoryKey} className='space-y-4'>
                    <h3 className='text-lg font-semibold text-neutral-200 border-b border-neutral-700 pb-2'>
                        {categoryTitles[categoryKey] || categoryKey}
                    </h3>

                    <div className='space-y-3'>
                        {settings.map((setting) => (
                            <div
                                key={setting.id}
                                className='flex items-center justify-between p-4 bg-neutral-800 rounded-lg'
                            >
                                <div className='flex-1'>
                                    <div className='flex items-center gap-3'>
                                        <Label>{setting.name}</Label>
                                        {setting.rate_limit_exempt && (
                                            <span className='px-2 py-1 text-xs bg-blue-600 text-white rounded'>
                                                Rate Limit Exempt
                                            </span>
                                        )}
                                    </div>
                                    {setting.description && (
                                        <p className='text-sm text-neutral-400 mt-1'>{setting.description}</p>
                                    )}
                                    <p className='text-xs text-neutral-500 mt-1 font-mono'>{setting.template_key}</p>
                                </div>

                                <Button
                                    onClick={() => toggleSetting(setting)}
                                    disabled={toggling[setting.template_key] || !globalEnabled}
                                    className={`ml-4 px-4 py-2 ${
                                        setting.enabled
                                            ? 'bg-green-600 hover:bg-green-700'
                                            : 'bg-red-600 hover:bg-red-700'
                                    }`}
                                >
                                    {toggling[setting.template_key] ? (
                                        <Spinner size='small' />
                                    ) : setting.enabled ? (
                                        'Enabled'
                                    ) : (
                                        'Disabled'
                                    )}
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            ))}

            {Object.keys(categories).length === 0 && (
                <div className='text-center py-8 text-neutral-400'>No email notification types configured</div>
            )}
        </div>
    );
};
