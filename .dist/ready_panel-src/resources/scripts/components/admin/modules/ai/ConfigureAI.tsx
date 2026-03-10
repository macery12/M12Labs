import { updateSettings } from '@/api/routes/admin/ai/settings';
import Input from '@/elements/Input';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useFlashKey } from '@/plugins/useFlash';
import { Dialog } from '@/elements/dialog';
import { faCheckCircle, faExclamationTriangle, faExternalLink } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Form, Formik, FormikHelpers } from 'formik';
import { useState } from 'react';
import { object, string } from 'yup';
import { useStoreState } from '@/state/hooks';

interface ConfigureAIProps {
    onDismiss?: () => void;
}

interface Values {
    mode: string;
    endpoint: string;
    key?: string;
    model: string;
}

export default ({ onDismiss }: ConfigureAIProps) => {
    const [mode, setMode] = useState<string>('openai');
    const [endpoint, setEndpoint] = useState<string>('https://api.openai.com/v1');
    const [key, setKey] = useState<string>('');
    const [model, setModel] = useState<string>('gpt-3.5-turbo');
    const [loading, setLoading] = useState<boolean>(false);
    const { primary } = useStoreState(state => state.theme.data!.colors);

    const { addFlash, clearAndAddHttpError } = useFlashKey('admin:settings:ai');

    const validation = object({
        endpoint: string().required().url(),
        model: string().required(),
        key: string().optional(),
    });

    const submit = (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        setLoading(true);

        updateSettings({
            mode: values.mode,
            endpoint: values.endpoint,
            key: values.mode === 'ollama' ? undefined : values.key,
            model: values.model,
        })
            .then(() => {
                addFlash({
                    type: 'success',
                    title: 'Success',
                    message: 'AI settings have been updated.',
                });
                onDismiss?.();
            })
            .catch(error => {
                setSubmitting(false);
                setLoading(false);
                clearAndAddHttpError(error);
            });
    };

    const isValidKey = mode === 'ollama' || (key !== undefined && key.length >= 20);

    const handleModeChange = (newMode: string) => {
        setMode(newMode);
        if (newMode === 'ollama') {
            setEndpoint('http://localhost:11434/v1');
            setModel('phi3:mini');
        } else {
            setEndpoint('https://api.openai.com/v1');
            setModel('gpt-3.5-turbo');
        }
    };

    const ollamaSmallModelSuggestions = [
        'phi3:mini',
        'phi3:small',
        'gemma2:2b',
        'qwen2.5:1.5b',
        'qwen2.5:3b',
        'deepseek-coder:1.3b',
        'starcoder2:3b',
    ];

    return (
        <Dialog open onClose={() => onDismiss?.()} title={'Configure Jexactyl AI'}>
            <SpinnerOverlay visible={loading} />
            <div className={'mt-2 text-sm text-gray-400'}>
                <p>
                    In order to use <span style={{ color: primary }}>Jexactyl AI</span>, you must configure an
                    OpenAI-compatible API endpoint.
                </p>
                <p className={'my-2 text-gray-400'}>
                    You can use{' '}
                    <a
                        href={'https://github.com/ollama/ollama'}
                        target={'_blank'}
                        rel={'noreferrer'}
                        className={'text-blue-400 hover:text-blue-300'}
                    >
                        Ollama
                        <FontAwesomeIcon icon={faExternalLink} className={'ml-1'} />
                    </a>{' '}
                    for local AI or an OpenAI-compatible API provider.
                </p>
                <div className={'mt-3 rounded bg-neutral-900/60 p-3'}>
                    <div className={'flex items-start gap-2'}>
                        <FontAwesomeIcon icon={faExclamationTriangle} className={'mt-0.5 text-yellow-400'} />
                        <div>
                            <p className={'text-gray-300'}>
                                Make sure your endpoint supports the OpenAI Chat Completions API format.
                            </p>
                            <p className={'text-xs text-gray-500 mt-1'}>
                                If you use Ollama, enable the OpenAI-compatible endpoint and use the <code>/v1</code>{' '}
                                base URL.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <Formik
                initialValues={{ mode, endpoint, key, model } as Values}
                validationSchema={validation}
                onSubmit={submit}
                enableReinitialize
            >
                {({ isSubmitting, values, setFieldValue }) => (
                    <Form className={'mt-6'}>
                        <div className={'mb-4'}>
                            <label className={'block text-sm text-gray-400 mb-1'}>Mode</label>
                            <div className={'flex items-center gap-3'}>
                                <button
                                    type={'button'}
                                    className={`px-3 py-2 rounded text-sm border ${
                                        values.mode === 'openai'
                                            ? 'border-green-500 text-green-300'
                                            : 'border-gray-700 text-gray-400'
                                    }`}
                                    onClick={() => {
                                        setFieldValue('mode', 'openai');
                                        handleModeChange('openai');
                                    }}
                                >
                                    Standard
                                </button>
                                <button
                                    type={'button'}
                                    className={`px-3 py-2 rounded text-sm border ${
                                        values.mode === 'ollama'
                                            ? 'border-green-500 text-green-300'
                                            : 'border-gray-700 text-gray-400'
                                    }`}
                                    onClick={() => {
                                        setFieldValue('mode', 'ollama');
                                        handleModeChange('ollama');
                                    }}
                                >
                                    Ollama
                                </button>
                                <Tooltip
                                    content={
                                        values.mode === 'ollama'
                                            ? 'Ollama mode uses a local/remote model and no API key'
                                            : 'Standard mode requires HTTPS and an API key'
                                    }
                                >
                                    <FontAwesomeIcon icon={faCheckCircle} className={'text-gray-600'} />
                                </Tooltip>
                            </div>
                        </div>

                        <div className={'mb-4'}>
                            <label className={'block text-sm text-gray-400 mb-1'}>API Endpoint URL</label>
                            <Input
                                placeholder={
                                    mode === 'ollama' ? 'http://localhost:11434/v1' : 'https://api.openai.com/v1'
                                }
                                value={endpoint}
                                onChange={e => setEndpoint(e.currentTarget.value)}
                            />
                            <p className={'mt-1 text-xs text-gray-500'}>
                                {mode === 'ollama'
                                    ? 'Default Ollama endpoint. Can be HTTP for local or HTTPS for remote.'
                                    : 'The base URL for the OpenAI-compatible API endpoint. Must use HTTPS. Example: https://api.openai.com/v1'}
                            </p>
                        </div>

                        <div className={'mb-4'}>
                            <label className={'block text-sm text-gray-400 mb-1'}>Model Name</label>
                            <Input
                                placeholder={mode === 'ollama' ? 'phi3:mini' : 'gpt-3.5-turbo'}
                                value={model}
                                onChange={e => setModel(e.currentTarget.value)}
                            />
                            <p className={'mt-1 text-xs text-gray-500'}>
                                {mode === 'ollama'
                                    ? `Ollama model name (smaller models recommended for Minecraft log reading / debugging). Examples: ${ollamaSmallModelSuggestions.join(
                                          ', ',
                                      )}`
                                    : 'OpenAI model name (e.g., gpt-3.5-turbo, gpt-4)'}
                            </p>
                        </div>

                        {mode === 'openai' && (
                            <div className={'relative mb-6'}>
                                <label className={'block text-sm text-gray-400 mb-1'}>API Key</label>
                                <Input
                                    type={'password'}
                                    value={key}
                                    onChange={e => setKey(e.currentTarget.value)}
                                    placeholder={'sk-...'}
                                />
                                <p className={'mt-1 text-xs text-gray-500'}>
                                    Your API key is stored securely and used to authenticate requests.
                                </p>

                                {!isValidKey && (
                                    <div className={'mt-2 text-xs text-red-400'}>
                                        API key looks too short. Make sure you pasted the full key.
                                    </div>
                                )}
                            </div>
                        )}

                        <div className={'flex items-center justify-end gap-3'}>
                            <button
                                type={'button'}
                                className={'px-4 py-2 rounded bg-gray-700 text-gray-200 hover:bg-gray-600'}
                                onClick={() => onDismiss?.()}
                                disabled={isSubmitting || loading}
                            >
                                Cancel
                            </button>
                            <button
                                type={'submit'}
                                className={
                                    'px-4 py-2 rounded bg-green-600 text-white hover:bg-green-500 disabled:opacity-50'
                                }
                                disabled={isSubmitting || loading || !isValidKey}
                                onClick={() => {
                                    // keep Formik values in sync with local state
                                    setFieldValue('endpoint', endpoint);
                                    setFieldValue('model', model);
                                    setFieldValue('key', key);
                                    setFieldValue('mode', mode);
                                }}
                            >
                                Save
                            </button>
                        </div>
                    </Form>
                )}
            </Formik>
        </Dialog>
    );
};
