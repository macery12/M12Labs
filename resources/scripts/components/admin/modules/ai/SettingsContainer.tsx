import Field from '@/elements/Field';
import Label from '@/elements/Label';
import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faUser, faLink, faCog, faServer, faHashtag } from '@fortawesome/free-solid-svg-icons';
import { AISettings, updateSettings } from '@/api/routes/admin/ai/settings';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import SelectField from '@/elements/SelectField';

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
                // Reload to apply new settings
                setTimeout(() => window.location.reload(), 500);
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
                mode: ai.mode || 'openai',
                max_tokens: ai.max_tokens || 200,
            }}
        >
            {({ values }) => (
                <Form>
                    <div className={'grid gap-4 lg:grid-cols-4'}>
                        <AdminBox title={'AI Provider Mode'} icon={faServer}>
                            <div>
                                <Field as="select" id={'mode'} name={'mode'}>
                                    <option value="openai">OpenAI / Standard (HTTPS)</option>
                                    <option value="ollama">Ollama (Local/HTTPS)</option>
                                </Field>
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    {values.mode === 'ollama'
                                        ? 'Ollama mode allows HTTP for local connections and does not require an API key.'
                                        : 'Standard mode requires HTTPS endpoint and API key for security.'}
                                </p>
                            </div>
                        </AdminBox>
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
                                    If enabled, standard Jexactyl users will be able to interact with Jexactyl AI as
                                    well as administrators.
                                </p>
                            </div>
                        </AdminBox>
                        {values.mode === 'openai' && (
                            <AdminBox title={'Modify API Key'} icon={faKey}>
                                <div>
                                    <Field id={'key'} name={'key'} type={'input'} />
                                    <p className={'mt-1.5 text-xs text-gray-400'}>
                                        Enter your OpenAI API key or a compatible API key from another provider.
                                    </p>
                                </div>
                            </AdminBox>
                        )}
                        <AdminBox title={'API Endpoint'} icon={faLink}>
                            <div>
                                <Field id={'endpoint'} name={'endpoint'} type={'input'} />
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    {values.mode === 'ollama'
                                        ? 'Ollama endpoint (default: http://localhost:11434/v1). Can use HTTP for local or HTTPS for remote.'
                                        : 'The base URL for the OpenAI-compatible API endpoint. Must use HTTPS.'}
                                </p>
                            </div>
                        </AdminBox>
                        <AdminBox title={'Model Configuration'} icon={faCog}>
                            <div>
                                <Field id={'model'} name={'model'} type={'input'} />
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    {values.mode === 'ollama'
                                        ? 'Ollama model name (e.g., llama2, mistral, codellama)'
                                        : 'The AI model to use (e.g., gpt-3.5-turbo, gpt-4, or any compatible model).'}
                                </p>
                            </div>
                        </AdminBox>
                        <AdminBox title={'Max Tokens'} icon={faHashtag}>
                            <div>
                                <Field id={'max_tokens'} name={'max_tokens'} type={'number'} min={50} max={4000} />
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    Maximum number of tokens in the AI response (50-4000). Default: 200. Lower values = shorter responses, less cost.
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
            )}
        </Formik>
    );
};
