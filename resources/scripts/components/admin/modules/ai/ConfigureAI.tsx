import { updateSettings } from '@/api/routes/admin/ai/settings';
import Input from '@/elements/Input';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useFlashKey } from '@/plugins/useFlash';
import { Dialog } from '@/elements/dialog';
import { faCheckCircle, faExclamationTriangle, faExternalLink } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useStoreState } from 'easy-peasy';
import { useState } from 'react';
import { Button } from '@/elements/button';

export default () => {
    const [key, setKey] = useState<string>();
    const [mode, setMode] = useState<string>('openai');
    const [endpoint, setEndpoint] = useState<string>(
        mode === 'ollama' ? 'http://localhost:11434/v1' : 'https://api.openai.com/v1'
    );
    const [model, setModel] = useState<string>(mode === 'ollama' ? 'llama2' : 'gpt-3.5-turbo');
    const settings = useStoreState(s => s.everest.data!.ai);
    const [loading, setLoading] = useState<boolean>(false);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:ai');

    const theme = useStoreState(s => s.theme.data!.colors);

    const submit = () => {
        clearFlashes();
        setLoading(true);

        updateSettings({ ...settings, key: mode === 'ollama' ? '' : key, endpoint, model, mode })
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                setLoading(false);
                clearAndAddHttpError(error);
            });
    };

    const isValidKey = mode === 'ollama' || (key !== undefined && key.length >= 20);

    const handleModeChange = (newMode: string) => {
        setMode(newMode);
        if (newMode === 'ollama') {
            setEndpoint('http://localhost:11434/v1');
            setModel('llama2');
        } else {
            setEndpoint('https://api.openai.com/v1');
            setModel('gpt-3.5-turbo');
        }
    };

    return (
        <Dialog open onClose={() => undefined} preventExternalClose hideCloseIcon title={'Configure Jexpanel AI'}>
            <SpinnerOverlay visible={loading} />
            <p className={'text-gray-400'}>
                In order to use <span style={{ color: theme.primary }}>Jexpanel AI</span>, you must configure an
                OpenAI-compatible API endpoint.
            </p>
            <p className={'my-2 text-gray-400'}>
                You can use{' '}
                <a
                    href={'https://platform.openai.com/api-keys'}
                    rel={'noreferrer'}
                    target={'_blank'}
                    className={'text-blue-400'}
                >
                    OpenAI
                    <FontAwesomeIcon icon={faExternalLink} className={'mb-1.5 ml-0.5 h-2 w-2'} />
                </a>
                , LocalAI, Ollama, or any other OpenAI-compatible service.
            </p>

            <div className={'mb-4'}>
                <label className={'block text-sm text-gray-400 mb-1'}>AI Provider Mode</label>
                <select
                    value={mode}
                    onChange={e => handleModeChange(e.currentTarget.value)}
                    className={'w-full rounded border border-gray-600 bg-gray-700 px-3 py-2 text-sm text-gray-200'}
                >
                    <option value="openai">OpenAI / Standard (HTTPS required)</option>
                    <option value="ollama">Ollama (Local/HTTPS)</option>
                </select>
                <p className={'mt-1 text-xs text-gray-500'}>
                    {mode === 'ollama'
                        ? 'Ollama mode allows HTTP for local connections and does not require an API key'
                        : 'Standard mode requires HTTPS and an API key'}
                </p>
            </div>

            <div className={'mb-4'}>
                <label className={'block text-sm text-gray-400 mb-1'}>API Endpoint URL</label>
                <Input
                    placeholder={mode === 'ollama' ? 'http://localhost:11434/v1' : 'https://api.openai.com/v1'}
                    value={endpoint}
                    onChange={e => setEndpoint(e.currentTarget.value)}
                />
                <p className={'mt-1 text-xs text-gray-500'}>
                    {mode === 'ollama'
                        ? 'Default Ollama endpoint. Can be HTTP for local or HTTPS for remote.'
                        : 'Must use HTTPS for security. Example: https://api.openai.com/v1 or https://your-server.com/v1'}
                </p>
            </div>

            <div className={'mb-4'}>
                <label className={'block text-sm text-gray-400 mb-1'}>Model Name</label>
                <Input
                    placeholder={mode === 'ollama' ? 'llama2' : 'gpt-3.5-turbo'}
                    value={model}
                    onChange={e => setModel(e.currentTarget.value)}
                />
                <p className={'mt-1 text-xs text-gray-500'}>
                    {mode === 'ollama'
                        ? 'Ollama model name (e.g., llama2, mistral, codellama)'
                        : 'OpenAI model name (e.g., gpt-3.5-turbo, gpt-4)'}
                </p>
            </div>

            {mode === 'openai' && (
                <div className={'relative mb-6'}>
                    <label className={'block text-sm text-gray-400 mb-1'}>API Key</label>
                    <Input placeholder={'Enter API key here...'} onChange={e => setKey(e.currentTarget.value)} />
                    {!isValidKey ? (
                        <Tooltip
                            placement={'right'}
                            content={
                                'API key must be at least 20 characters. OpenAI keys are typically 50+ characters and start with "sk-".'
                            }
                        >
                            <FontAwesomeIcon
                                icon={faExclamationTriangle}
                                className={'absolute top-2/3 right-4 text-yellow-500'}
                            />
                        </Tooltip>
                    ) : (
                        <FontAwesomeIcon icon={faCheckCircle} className={'absolute top-2/3 right-4 text-green-500'} />
                    )}
                </div>
            )}

            {mode === 'ollama' && (
                <div className={'mb-6 rounded bg-blue-900/20 border border-blue-700/30 p-3'}>
                    <p className={'text-sm text-blue-300'}>
                        <strong>Ollama Mode:</strong> No API key required. Make sure Ollama is running and the model is
                        pulled.
                    </p>
                </div>
            )}

            <div className={'flex justify-end'}>
                <Button onClick={submit} disabled={!isValidKey || loading}>
                    Save Configuration
                </Button>
            </div>
        </Dialog>
    );
};
