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
    const [endpoint, setEndpoint] = useState<string>('https://api.openai.com/v1');
    const [model, setModel] = useState<string>('gpt-3.5-turbo');
    const settings = useStoreState(s => s.everest.data!.ai);
    const [loading, setLoading] = useState<boolean>(false);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('admin:ai');

    const theme = useStoreState(s => s.theme.data!.colors);

    const submit = () => {
        clearFlashes();
        setLoading(true);

        updateSettings({ ...settings, key, endpoint, model })
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                setLoading(false);
                clearAndAddHttpError(error);
            });
    };

    const isValidKey = key !== undefined && key.length >= 20;

    return (
        <Dialog open onClose={() => undefined} preventExternalClose hideCloseIcon title={'Configure Jexactyl AI'}>
            <SpinnerOverlay visible={loading} />
            <p className={'text-gray-400'}>
                In order to use <span style={{ color: theme.primary }}>Jexactyl AI</span>, you must configure an
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
                , or any OpenAI-compatible service like LocalAI, Ollama, or other providers.
            </p>
            <div className={'mb-4'}>
                <label className={'block text-sm text-gray-400 mb-1'}>API Endpoint URL</label>
                <Input
                    placeholder={'https://api.openai.com/v1'}
                    value={endpoint}
                    onChange={e => setEndpoint(e.currentTarget.value)}
                />
                <p className={'mt-1 text-xs text-gray-500'}>
                    Must use HTTPS for security. Example: https://api.openai.com/v1 or https://your-server.com/v1
                </p>
            </div>
            <div className={'mb-4'}>
                <label className={'block text-sm text-gray-400 mb-1'}>Model Name</label>
                <Input placeholder={'gpt-3.5-turbo'} value={model} onChange={e => setModel(e.currentTarget.value)} />
            </div>
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
            <div className={'flex justify-end'}>
                <Button onClick={submit} disabled={!isValidKey || loading}>
                    Save Configuration
                </Button>
            </div>
        </Dialog>
    );
};
