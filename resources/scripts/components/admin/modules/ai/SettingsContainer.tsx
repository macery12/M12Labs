import Field from '@/elements/Field';
import Label from '@/elements/Label';
import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faKey, faUser, faLink, faCog, faServer, faHashtag, faComment, faTrash } from '@fortawesome/free-solid-svg-icons';
import { AISettings, updateSettings } from '@/api/routes/admin/ai/settings';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import SelectField from '@/elements/SelectField';
import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

export default () => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const ai = useStoreState(s => s.everest.data!.ai);
    const [deletingKey, setDeletingKey] = useState(false);

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

    const deleteApiKey = () => {
        if (!confirm('Are you sure you want to delete the API key? This will disable AI functionality until a new key is configured.')) {
            return;
        }

        setDeletingKey(true);
        clearFlashes();

        updateSettings({ ...ai, key: '' })
            .then(() => {
                addFlash({
                    type: 'success',
                    key: 'admin:ai:settings',
                    message: 'API key has been deleted successfully.',
                });
                // Reload to apply new settings
                setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                setDeletingKey(false);
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
                system_prompt: ai.system_prompt || 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.',
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
                                    <Field id={'key'} name={'key'} type={'input'} placeholder="Enter new API key to update" />
                                    <p className={'mt-1.5 text-xs text-gray-400'}>
                                        Enter your OpenAI API key or a compatible API key from another provider.
                                    </p>
                                    {ai.key && (
                                        <button
                                            type="button"
                                            onClick={deleteApiKey}
                                            disabled={deletingKey}
                                            className={'mt-2 inline-flex items-center rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed'}
                                        >
                                            <FontAwesomeIcon icon={faTrash} className={'mr-1.5 h-3 w-3'} />
                                            {deletingKey ? 'Deleting...' : 'Delete API Key'}
                                        </button>
                                    )}
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
                        <AdminBox title={'System Prompt'} icon={faComment} className={'col-span-2'}>
                            <div>
                                <Field
                                    as="textarea"
                                    id={'system_prompt'}
                                    name={'system_prompt'}
                                    rows={3}
                                    placeholder="You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses."
                                />
                                <p className={'mt-1.5 text-xs text-gray-400'}>
                                    Customize the AI's behavior and response style. This message is sent with every query to define the AI's role and tone. (10-1000 characters)
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
