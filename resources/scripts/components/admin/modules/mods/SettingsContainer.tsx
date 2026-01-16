import Field from '@/elements/Field';
import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faPowerOff, faTrash } from '@fortawesome/free-solid-svg-icons';
import { ModsSettings, updateSettings, resetCurseForgeKey } from '@/api/routes/admin/mods/settings';
import { useFlashKey } from '@/plugins/useFlash';
import { Button } from '@/elements/button';

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlashKey('admin:mods');
    const mods = useStoreState(s => s.everest.data!.mods);

    const submit = (values: ModsSettings) => {
        clearFlashes();

        // Only send values that are actually set
        const payload: ModsSettings = {
            enabled: values.enabled,
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
                    type: 'success',
                    message: 'Settings have been updated successfully.',
                });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                clearAndAddHttpError(error);
            });
    };

    const handleResetKey = () => {
        if (!confirm('Are you sure you want to delete the CurseForge API key?')) return;

        clearFlashes();
        resetCurseForgeKey()
            .then(() => {
                addFlash({
                    type: 'success',
                    message: 'API key has been deleted successfully.',
                });
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                clearAndAddHttpError(error);
            });
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                enabled: mods.enabled,
                curseforge_api_key: '',
            }}
        >
            {() => (
                <Form>
                    <div className={'grid gap-4 lg:grid-cols-3'}>
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
                                    .
                                </p>
                                {mods.curseforge_api_key && (
                                    <p className={'mt-2 text-xs text-green-400'}>✓ API key is currently configured</p>
                                )}
                            </div>
                        </AdminBox>

                        <AdminBox title={'Module Status'} icon={faPowerOff}>
                            <div>
                                <label className={'flex items-center'}>
                                    <Field id={'enabled'} name={'enabled'} type={'checkbox'} className={'mr-2'} />
                                    <span>Enable Mods Module</span>
                                </label>
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    When enabled, users can search for and install Minecraft mods through the panel.
                                </p>
                            </div>
                        </AdminBox>

                        {mods.curseforge_api_key && (
                            <AdminBox title={'Reset API Key'} icon={faTrash}>
                                <div>
                                    <p className={'text-sm text-gray-400'}>
                                        Delete the current CurseForge API key from the database. You will need to enter
                                        a new key to use the Mods module.
                                    </p>
                                    <div className={'mt-3 text-right'}>
                                        <Button.Danger onClick={handleResetKey}>Delete API Key</Button.Danger>
                                    </div>
                                </div>
                            </AdminBox>
                        )}
                    </div>
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
