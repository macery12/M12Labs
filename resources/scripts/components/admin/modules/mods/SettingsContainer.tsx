import Field from '@/elements/Field';
import { Form, Formik, Field as FormikField } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faPowerOff, faTrash, faGlobe } from '@fortawesome/free-solid-svg-icons';
import { ModsSettings, updateSettings, resetCurseForgeKey } from '@/api/routes/admin/mods/settings';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import Label from '@/elements/Label';
import Select from '@/elements/Select';

const SettingsContainer = () => {
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();
    const mods = useStoreState(s => s.everest.data!.mods);

    const submit = (values: ModsSettings) => {
        clearFlashes('admin:mods');

        // Only send values that are actually set
        const payload: ModsSettings = {
            enabled: values.enabled,
            default_source: values.default_source,
            spiget_enabled: values.spiget_enabled,
        };

        // Only include API key if it was provided
        if (
            values.curseforge_api_key &&
            typeof values.curseforge_api_key === 'string' &&
            values.curseforge_api_key.length > 0
        ) {
            payload.curseforge_api_key = values.curseforge_api_key;
        }

        updateSettings(payload)
            .then(() => {
                addFlash({
                    key: 'admin:mods',
                    type: 'success',
                    message: 'Settings have been updated successfully.',
                });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'admin:mods', error });
            });
    };

    const handleResetKey = () => {
        if (!confirm('Are you sure you want to delete the CurseForge API key?')) return;

        clearFlashes('admin:mods');
        resetCurseForgeKey()
            .then(() => {
                addFlash({
                    key: 'admin:mods',
                    type: 'success',
                    message: 'API key has been deleted successfully.',
                });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'admin:mods', error });
            });
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                enabled: mods.enabled,
                curseforge_api_key: typeof mods.curseforge_api_key === 'string' ? '' : '',
                default_source: mods.default_source || 'modrinth',
                spiget_enabled: mods.spiget_enabled ?? false,
            }}
        >
            {() => (
                <Form>
                    <div className={'grid gap-4 lg:grid-cols-3'}>
                        <AdminBox title={'Module Status'} icon={faPowerOff}>
                            <div>
                                <label className={'flex items-center'}>
                                    <Field id={'enabled'} name={'enabled'} type={'checkbox'} className={'mr-2'} />
                                    <span>Enable Mods Module</span>
                                </label>
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    When enabled, users can search for and install Minecraft mods through the panel.
                                </p>
                                <label className={'flex items-center mt-3'}>
                                    <Field
                                        id={'spiget_enabled'}
                                        name={'spiget_enabled'}
                                        type={'checkbox'}
                                        className={'mr-2'}
                                    />
                                    <span>Enable Spiget (Spigot plugins)</span>
                                </label>
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    Allow users to browse and install Spigot/Paper/Purpur plugins via Spiget.
                                </p>
                            </div>
                        </AdminBox>

                        <AdminBox title={'Default Mod Source'} icon={faGlobe}>
                            <div>
                                <Label>Mod Source</Label>
                                <FormikField as={Select} id={'default_source'} name={'default_source'}>
                                    <option value="modrinth">Modrinth (No API Key Required)</option>
                                    <option value="curseforge">CurseForge (Requires API Key)</option>
                                    <option value="spiget">Spiget (Spigot plugins)</option>
                                </FormikField>
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    Select the default mod source. Modrinth is free and doesn&apos;t require an API key.
                                    Users can switch between sources if both are configured.
                                </p>
                            </div>
                        </AdminBox>

                        <AdminBox title={'CurseForge API Key'} icon={faKey}>
                            <div>
                                <Field id={'curseforge_api_key'} name={'curseforge_api_key'} type={'password'} />
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    Enter your CurseForge API key. Get one from the{' '}
                                    <a
                                        href="https://console.curseforge.com/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline"
                                    >
                                        CurseForge Console
                                    </a>
                                    . Optional - only needed if you want to use CurseForge as a source.
                                </p>
                                {mods.curseforge_api_key && (
                                    <p className={'mt-2 text-xs text-green-400'}>✓ API key is currently configured</p>
                                )}
                            </div>
                        </AdminBox>
                    </div>

                    {mods.curseforge_api_key && (
                        <div className={'mt-4 grid gap-4 lg:grid-cols-3'}>
                            <AdminBox title={'Reset CurseForge API Key'} icon={faTrash}>
                                <div>
                                    <p className={'text-sm text-gray-400'}>
                                        Delete the current CurseForge API key from the database. You will need to enter
                                        a new key to use CurseForge as a mod source.
                                    </p>
                                    <div className={'mt-3 text-right'}>
                                        <Button.Danger onClick={handleResetKey}>Delete API Key</Button.Danger>
                                    </div>
                                </div>
                            </AdminBox>
                        </div>
                    )}
                    <div className={'mt-6 flex w-full flex-row items-center'}>
                        <div className={'flex text-xs text-gray-500'}>
                            These changes may not apply until this page is reloaded.
                        </div>
                        <div className={'ml-auto flex'}>
                            <Button type="submit">Save Changes</Button>
                        </div>
                    </div>
                </Form>
            )}
        </Formik>
    );
};

export default SettingsContainer;
