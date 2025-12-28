import Field from '@/elements/Field';
import Label from '@/elements/Label';
import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faUser, faLink, faCog } from '@fortawesome/free-solid-svg-icons';
import { AISettings, updateSettings } from '@/api/routes/admin/ai/settings';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const ai = useStoreState(s => s.everest.data!.ai);

    const submit = (values: AISettings) => {
        clearFlashes();

        updateSettings(values)
            .then(() => {
                addFlash({
                    type: 'success',
                    key: 'admin:ai:settings',
                    message: 'Settings have been updated successfully.',
                });
            })
            .catch(error => {
                clearAndAddHttpError({
                    key: 'admin:ai:settings',
                    error: error,
                });
            });
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                user_access: ai.user_access,
                endpoint: ai.endpoint || 'https://api.openai.com/v1',
                model: ai.model || 'gpt-3.5-turbo',
            }}
        >
            <Form>
                <div className={'grid gap-4 lg:grid-cols-4'}>
                    <AdminBox title={'Client-side AI'} icon={faUser}>
                        <div>
                            <div className={'inline-flex'}>
                                <Label className={'mt-1 mr-2'}>Allow standard users to use AI?</Label>
                                <Field
                                    id={'user_access'}
                                    name={'user_access'}
                                    type={'checkbox'}
                                    defaultChecked={ai.user_access}
                                />
                            </div>
                            <p className={'mt-1.5 text-xs text-gray-400'}>
                                If enabled, standard Jexactyl users will be able to interact with Jexactyl AI as well as
                                administrators.
                            </p>
                        </div>
                    </AdminBox>
                    <AdminBox title={'Modify API Key'} icon={faKey}>
                        <div>
                            <Field id={'key'} name={'key'} type={'input'} />
                            <p className={'mt-1.5 text-xs text-gray-400'}>
                                Enter your OpenAI API key or a compatible API key from another provider.
                            </p>
                        </div>
                    </AdminBox>
                    <AdminBox title={'API Endpoint'} icon={faLink}>
                        <div>
                            <Field id={'endpoint'} name={'endpoint'} type={'input'} />
                            <p className={'mt-1.5 text-xs text-gray-400'}>
                                The base URL for the OpenAI-compatible API endpoint. Default is OpenAI&apos;s API.
                            </p>
                        </div>
                    </AdminBox>
                    <AdminBox title={'Model Configuration'} icon={faCog}>
                        <div>
                            <Field id={'model'} name={'model'} type={'input'} />
                            <p className={'mt-1.5 text-xs text-gray-400'}>
                                The AI model to use (e.g., gpt-3.5-turbo, gpt-4, or any compatible model).
                            </p>
                        </div>
                    </AdminBox>
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
        </Formik>
    );
};
