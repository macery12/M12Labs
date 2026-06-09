import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState, useStoreActions } from '@/state/hooks';
import { CogIcon, TrashIcon } from '@heroicons/react/outline';
import { MarketplaceSettings, updateSettings } from '@/api/routes/admin/marketplace/settings';
import { useFlashKey } from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import ToggleSwitch from '@/elements/ToggleSwitch';
import ToggleFeatureButton from './ToggleFeatureButton';

const SettingsContainer = () => {
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlashKey('admin:marketplace');
    const mods = useStoreState(s => s.everest.data!.mods);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);

    const submit = (values: MarketplaceSettings) => {
        clearFlashes();

        const payload: MarketplaceSettings = {
            enabled: values.enabled,
            default_source: values.default_source,
            allow_external_downloads: values.allow_external_downloads,
        };

        updateSettings(payload)
            .then(() => {
                updateEverest({ mods: { ...mods, ...payload } });
                addFlash({ type: 'success', message: 'Marketplace settings saved.' });
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
                                                Allow users to search and install mods and plugins through the panel.
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
                                            <option value="modrinth">Modrinth</option>
                                            <option value="spigot">Spigot</option>
                                        </Select>
                                        <p className={'text-xs text-neutral-400'}>
                                            The default source shown to users.
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
                        Disabling the Marketplace module removes access for all users.
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
