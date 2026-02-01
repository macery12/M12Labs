import Input from '@/elements/Input';
import { useStoreState } from '@/state/hooks';
import { Dialog } from '@/elements/dialog';
import { faExclamationTriangle, faCheckCircle, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useState } from 'react';
import { Button } from '@/elements/button';
import { updateSettings } from '@/api/routes/admin/billing';

interface MollieKeys {
    apiKey?: string;
}

export default ({ open, onClose }: { open: boolean; onClose: () => void }) => {
    const [data, setData] = useState<MollieKeys>({});
    const existingMollie = useStoreState(s => s.everest.data!.billing.mollie);

    const submit = async () => {
        if (!data.apiKey) return;

        await updateSettings('mollie:api_key', data.apiKey);
        window.location.reload();
    };

    const isValid = () => {
        return data.apiKey && data.apiKey.length > 10;
    };

    return (
        <Dialog open={open} onClose={onClose} title={'Configure Mollie API Key'}>
            <div className={'mb-4 rounded-lg bg-black/50 p-3'}>
                <p className={'font-semibold text-gray-200'}>
                    <FontAwesomeIcon icon={faInfoCircle} className={'mr-2 text-blue-400'} />
                    Mollie API Key
                </p>
                <p className={'text-sm text-gray-400'}>
                    You need your Mollie API key to process payments through Mollie.
                </p>
            </div>

            <p className={'mb-4'}>
                Before you can use the Mollie API, you must provide Jexactyl with your API key. Visit the Mollie
                dashboard
                <a
                    target={'_blank'}
                    rel={'noreferrer'}
                    className={'mx-1 text-blue-300'}
                    href={'https://www.mollie.com/dashboard/developers/api-keys'}
                >
                    here
                </a>
                to obtain your API key (starts with &quot;live_&quot; or &quot;test_&quot;), then paste it here.
            </p>
            <div className={'relative mt-4'}>
                <Input
                    placeholder={'Enter Mollie API key here...'}
                    defaultValue={''}
                    onChange={e => setData({ ...data, apiKey: e.currentTarget.value })}
                />
                {!data?.apiKey || data.apiKey.length < 10 ? (
                    <Tooltip placement={'right'} content={'You must enter a valid Mollie API key to continue.'}>
                        <FontAwesomeIcon
                            icon={faExclamationTriangle}
                            className={'absolute top-1/3 right-4 text-yellow-500'}
                        />
                    </Tooltip>
                ) : (
                    <FontAwesomeIcon icon={faCheckCircle} className={'absolute top-1/3 right-4 text-green-500'} />
                )}
            </div>
            <div className={'mt-4 w-full text-right'}>
                <Button onClick={submit} disabled={!isValid()}>
                    Save Mollie API Key
                </Button>
            </div>
        </Dialog>
    );
};
