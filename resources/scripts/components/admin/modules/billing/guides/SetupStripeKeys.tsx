import Input from '@/elements/Input';
import { useStoreState } from '@/state/hooks';
import { Dialog } from '@/elements/dialog';
import { faExclamationTriangle, faCheckCircle, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useEffect, useState } from 'react';
import { Button } from '@/elements/button';
import { updateSettings } from '@/api/routes/admin/billing';

interface StripeKeys {
    publishable?: string;
    secret?: string;
}

export default ({ open, onClose }: { open: boolean; onClose: () => void }) => {
    const [data, setData] = useState<StripeKeys>({});
    const existingKeys = useStoreState(s => s.everest.data!.billing.keys);

    const submit = async () => {
        if (!data.publishable || !data.secret) return;

        await updateSettings('keys:publishable', data.publishable);
        await updateSettings('keys:secret', data.secret);
        window.location.reload();
    };

    const isValid = () => {
        return (
            data.publishable &&
            data.secret &&
            data.publishable.length >= 100 &&
            data.publishable.length <= 120 &&
            data.secret.length >= 100 &&
            data.secret.length <= 120
        );
    };

    return (
        <Dialog open={open} onClose={onClose} title={'Configure Stripe API Keys'}>
            <div className={'mb-4 rounded-lg bg-black/50 p-3'}>
                <p className={'font-semibold text-gray-200'}>
                    <FontAwesomeIcon icon={faInfoCircle} className={'mr-2 text-blue-400'} />
                    Stripe API Keys
                </p>
                <p className={'text-sm text-gray-400'}>
                    You need both a publishable key and a secret key from your Stripe dashboard to process payments.
                </p>
            </div>

            <p className={'mb-4'}>
                Before you can use the Stripe API, you must provide Jexactyl with API keys to authenticate with Stripe.
                Visit the Stripe dashboard
                <a
                    target={'_blank'}
                    rel={'noreferrer'}
                    className={'mx-1 text-blue-300'}
                    href={'https://dashboard.stripe.com/apikeys'}
                >
                    here
                </a>
                to obtain your API key and secret key, then paste them here.
            </p>
            <div className={'relative mt-4'}>
                <Input
                    placeholder={'Enter "publishable" key here...'}
                    defaultValue={''}
                    onChange={e => setData({ ...data, publishable: e.currentTarget.value })}
                />
                {!data?.publishable || data.publishable.length < 100 || data.publishable.length > 120 ? (
                    <Tooltip placement={'right'} content={'You must enter a valid Stripe publishable key to continue.'}>
                        <FontAwesomeIcon
                            icon={faExclamationTriangle}
                            className={'absolute top-1/3 right-4 text-yellow-500'}
                        />
                    </Tooltip>
                ) : (
                    <FontAwesomeIcon icon={faCheckCircle} className={'absolute top-1/3 right-4 text-green-500'} />
                )}
            </div>
            <div className={'relative mt-4'}>
                <Input
                    placeholder={'Enter "secret" key here...'}
                    defaultValue={''}
                    onChange={e => setData({ ...data, secret: e.currentTarget.value })}
                />
                {!data?.secret || data.secret.length < 100 || data.secret.length > 120 ? (
                    <Tooltip placement={'right'} content={'You must enter a valid Stripe secret key to continue.'}>
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
                    Save Stripe Keys
                </Button>
            </div>
        </Dialog>
    );
};
