import { useEffect, useState } from 'react';
import {
    getNotificationSettings,
    updateGlobalNotificationToggle,
    updateNotificationSetting,
    type EmailNotificationSetting,
    type NotificationSettingsResponse,
} from '@/api/routes/admin/email';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Spinner from '@/elements/Spinner';
import Label from '@/elements/Label';
import { useStoreState } from '@/state/hooks';

export default () => {
    const [loading, setLoading] = useState(true);
    const [globalEnabled, setGlobalEnabled] = useState(true);
    const [categories, setCategories] = useState<Record<string, EmailNotificationSetting[]>>({});
    const [toggling, setToggling] = useState<Record<string, boolean>>({});
    const { clearFlashes, addFlash } = useFlash();
    const { secondary } = useStoreState((state) => state.theme.data!.colors);

    useEffect(() => {
        loadSettings();
    }, []);

    const loadSettings = async () => {
        setLoading(true);
        try {
            const data = await getNotificationSettings();
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
            await updateGlobalNotificationToggle(!globalEnabled);

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
            await updateNotificationSetting(setting.id, !setting.enabled);

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

    const GlobalToggleButton = globalEnabled ? Button.Success : Button.Danger;

    return (
        <div className='space-y-6'>
            {/* Global Toggle */}
            <div
                className='border border-neutral-700 rounded-lg p-4'
                style={{ backgroundColor: secondary }}
            >
                <div className='flex items-center justify-between'>
                    <div>
                        <h3 className='text-lg font-medium'>Global Email Notifications</h3>
                        <p className='text-sm text-neutral-300 mt-1'>
                            Master switch to enable or disable all email notifications
                        </p>
                    </div>
                    <GlobalToggleButton
                        onClick={() => {
                            if (!toggling.global) {
                                toggleGlobal();
                            }
                        }}
                        className={`px-4 py-2 ${toggling.global ? 'opacity-50 cursor-not-allowed' : ''}`}
                    >
                        {toggling.global ? <Spinner size='small' /> : globalEnabled ? 'Enabled' : 'Disabled'}
                    </GlobalToggleButton>
                </div>
            </div>

            {/* Category Groups */}
            {Object.entries(categories).map(([categoryKey, settings]) => (
                <div key={categoryKey} className='space-y-4'>
                    <h3 className='text-lg font-semibold text-neutral-200 border-b border-neutral-700 pb-2'>
                        {categoryTitles[categoryKey] || categoryKey}
                    </h3>

                    <div className='space-y-3'>
                        {settings.map((setting) => {
                            const ItemToggle = setting.enabled ? Button.Success : Button.Danger;

                            return (
                                <div
                                    key={setting.id}
                                    className='flex items-center justify-between rounded-lg border border-neutral-700 p-4'
                                    style={{ backgroundColor: secondary }}
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
                                            <p className='text-sm text-neutral-300 mt-1'>{setting.description}</p>
                                        )}
                                        <p className='text-xs text-neutral-400 mt-1 font-mono'>
                                            {setting.template_key}
                                        </p>
                                    </div>

                                    <ItemToggle
                                        onClick={() => {
                                            if (!toggling[setting.template_key] && globalEnabled) {
                                                toggleSetting(setting);
                                            }
                                        }}
                                        className={`ml-4 px-4 py-2 ${
                                            toggling[setting.template_key] || !globalEnabled
                                                ? 'opacity-50 cursor-not-allowed'
                                                : ''
                                        }`}
                                    >
                                        {toggling[setting.template_key] ? (
                                            <Spinner size='small' />
                                        ) : setting.enabled ? (
                                            'Enabled'
                                        ) : (
                                            'Disabled'
                                        )}
                                    </ItemToggle>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}

            {Object.keys(categories).length === 0 && (
                <div className='text-center py-8 text-neutral-400'>No email notification types configured</div>
            )}
        </div>
    );
};
