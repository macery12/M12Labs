import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState, useStoreActions } from '@/state/hooks';
import { CogIcon, KeyIcon, EyeIcon, EyeOffIcon, TrashIcon } from '@heroicons/react/outline';
import { MarketplaceSettings, updateSettings, resetCurseForgeKey } from '@/api/routes/admin/marketplace/settings';
import { useFlashKey } from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import Input from '@/elements/Input';
import ToggleSwitch from '@/elements/ToggleSwitch';
import { useState } from 'react';
import ToggleFeatureButton from './ToggleFeatureButton';

const SettingsContainer = () => {
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlashKey('admin:marketplace');
    const mods = useStoreState(s => s.everest.data!.mods);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const [showKey, setShowKey] = useState(false);

    const submit = (values: MarketplaceSettings) => {
        clearFlashes();

        const payload: MarketplaceSettings = {
            enabled: values.enabled,
            default_source: values.default_source,
            allow_external_downloads: values.allow_external_downloads,
        };

        if (
            values.curseforge_api_key &&
            typeof values.curseforge_api_key === 'string' &&
            values.curseforge_api_key.length > 0
        ) {
            payload.curseforge_api_key = values.curseforge_api_key;
        }

        updateSettings(payload)
            .then(() => {
                updateEverest({ mods: { ...mods, ...payload, curseforge_api_key: payload.curseforge_api_key ? true : mods.curseforge_api_key, allow_external_downloads: payload.allow_external_downloads } });
                addFlash({ type: 'success', message: 'Marketplace settings saved.' });
            })
            .catch(error => clearAndAddHttpError(error));
    };

    const handleResetKey = () => {
        if (!confirm('Delete the CurseForge API key? You will need to re-enter it to use CurseForge.')) return;

        clearFlashes();
        resetCurseForgeKey()
            .then(() => {
                updateEverest({ mods: { ...mods, curseforge_api_key: false } });
                addFlash({ type: 'success', message: 'CurseForge API key deleted.' });
            })
            .catch(error => clearAndAddHttpError(error));
    };

    return (
        <div className={'grid gap-4 lg:grid-cols-3'}>
            <div className={'col-span-2'}>
                <Formik
                    onSubmit={submit}
                    initialValues={{
                        enabled: mods.enabled,
                        curseforge_api_key: '',
                        default_source: mods.default_source || 'modrinth',
                        allow_external_downloads: mods.allow_external_downloads ?? false,
                    }}
                >
                    {({ values, setFieldValue }) => (
                        <Form>
                            <AdminBox title={'Marketplace Settings'} icon={CogIcon}>
                                <div className={'flex flex-col gap-6'}>
                                    {/* Enabled toggle */}
                                    <div className={'flex items-center justify-between gap-4'}>
                                        <div>
                                            <p className={'text-sm font-medium text-neutral-200'}>
                                                Enable Marketplace Module
                                            </p>
                                            <p className={'mt-0.5 text-xs text-neutral-400'}>
                                                Allow users to search and install mods, modpacks, and plugins through the
                                                panel.
                                            </p>
                                        </div>
                                        <ToggleSwitch
                                            checked={values.enabled}
                                            onChange={() => setFieldValue('enabled', !values.enabled)}
                                            label={'Toggle marketplace enabled'}
                                        />
                                    </div>

                                    <div className={'border-t border-neutral-700'} />

                                    {/* Default source */}
                                    <div className={'flex flex-col gap-2'}>
                                        <Label>Default Content Source</Label>
                                        <Select
                                            value={values.default_source}
                                            onChange={e => setFieldValue('default_source', e.target.value)}
                                        >
                                            <option value="modrinth">Modrinth (no API key required)</option>
                                            <option value="curseforge">CurseForge (requires API key)</option>
                                        </Select>
                                        <p className={'text-xs text-neutral-400'}>
                                            The default source shown to users. They can switch if both are configured.
                                        </p>
                                    </div>

                                    <div className={'border-t border-neutral-700'} />

                                    {/* External Downloads */}
                                    <div className={'flex items-start justify-between gap-4'}>
                                        <div className={'flex-1'}>
                                            <p className={'text-sm font-medium text-neutral-200'}>
                                                Allow External Downloads
                                            </p>
                                            <p className={'mt-0.5 text-xs text-neutral-400'}>
                                                Allows users to download Spigot plugins hosted on external sites (e.g.
                                                GitHub, plugin author websites). These sources are not verified by
                                                SpigotMC or Modrinth.
                                            </p>
                                            <div
                                                className={
                                                    'mt-2 rounded-md border border-yellow-500/30 bg-yellow-500/10 px-3 py-2 text-xs text-yellow-400'
                                                }
                                            >
                                                <strong>Disclaimer:</strong> External downloads may originate from
                                                unknown sources outside of SpigotMC. While most are safe, this option
                                                is disabled by default for security. Only enable it if you trust the
                                                plugins your users install.
                                            </div>
                                        </div>
                                        <ToggleSwitch
                                            checked={values.allow_external_downloads ?? false}
                                            onChange={() =>
                                                setFieldValue(
                                                    'allow_external_downloads',
                                                    !values.allow_external_downloads
                                                )
                                            }
                                            label={'Toggle external downloads'}
                                        />
                                    </div>

                                    <div className={'border-t border-neutral-700'} />

                                    {/* CurseForge API key */}
                                    <div className={'flex flex-col gap-2'}>
                                        <div className={'flex items-center gap-2'}>
                                            <KeyIcon className={'h-4 w-4 text-neutral-400'} />
                                            <Label className={'mb-0'}>CurseForge API Key</Label>
                                            {mods.curseforge_api_key && (
                                                <span
                                                    className={
                                                        'ml-auto rounded-full bg-green-500/15 px-2 py-0.5 text-xs font-medium text-green-400'
                                                    }
                                                >
                                                    Configured
                                                </span>
                                            )}
                                        </div>
                                        <div className={'flex items-center gap-2'}>
                                            <div className={'relative flex-1'}>
                                                <Input
                                                    name={'curseforge_api_key'}
                                                    type={showKey ? 'text' : 'password'}
                                                    value={values.curseforge_api_key as string}
                                                    onChange={e => setFieldValue('curseforge_api_key', e.target.value)}
                                                    placeholder={
                                                        mods.curseforge_api_key
                                                            ? 'Enter new key to replace existing'
                                                            : 'Enter your CurseForge API key'
                                                    }
                                                    className={'pr-10'}
                                                />
                                                <button
                                                    type={'button'}
                                                    onClick={() => setShowKey(v => !v)}
                                                    className={
                                                        'absolute right-3 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-200'
                                                    }
                                                    aria-label={showKey ? 'Hide API key' : 'Show API key'}
                                                >
                                                    {showKey ? (
                                                        <EyeOffIcon className={'h-4 w-4'} />
                                                    ) : (
                                                        <EyeIcon className={'h-4 w-4'} />
                                                    )}
                                                </button>
                                            </div>
                                            {mods.curseforge_api_key && (
                                                <button
                                                    type={'button'}
                                                    onClick={handleResetKey}
                                                    className={
                                                        'flex items-center gap-1.5 rounded-md border border-red-500/40 px-3 py-2 text-xs text-red-400 transition hover:border-red-500 hover:bg-red-500/10'
                                                    }
                                                >
                                                    <TrashIcon className={'h-3.5 w-3.5'} />
                                                    Delete
                                                </button>
                                            )}
                                        </div>
                                        <p className={'text-xs text-neutral-400'}>
                                            Get an API key from the{' '}
                                            <a
                                                href="https://console.curseforge.com/"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className={'underline hover:text-neutral-200'}
                                            >
                                                CurseForge Console
                                            </a>
                                            . Optional — only needed for CurseForge content.
                                        </p>
                                    </div>
                                </div>

                                <div className={'mt-6 flex items-center justify-between border-t border-neutral-700 pt-4'}>
                                    <p className={'text-xs text-neutral-500'}>Changes apply immediately after saving.</p>
                                    <Button type="submit">Save Changes</Button>
                                </div>
                            </AdminBox>
                        </Form>
                    )}
                </Formik>
            </div>

            {/* Danger zone */}
            <div>
                <AdminBox title={'Danger Zone'} icon={TrashIcon}>
                    <p className={'text-sm text-neutral-400'}>
                        Disabling the Marketplace module removes access for all users. Your CurseForge API key will
                        remain stored unless deleted above.
                    </p>
                    <div className={'mt-4'}>
                        <ToggleFeatureButton />
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};

export default SettingsContainer;
