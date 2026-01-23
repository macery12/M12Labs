import Input from '@/elements/Input';
import { useStoreState } from '@/state/hooks';
import { Dialog } from '@/elements/dialog';
import { faExclamationTriangle, faCheckCircle, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useEffect, useState } from 'react';
import { Button } from '@/elements/button';
import { updateSettings } from '@/api/routes/admin/billing';
import Label from '@/elements/Label';
import Select from '@/elements/Select';

interface PaymentKeys {
    processor?: 'stripe' | 'mollie';
    stripePublishable?: string;
    stripeSecret?: string;
    mollieApiKey?: string;
}

export default ({ extOpen }: { extOpen?: boolean }) => {
    const [data, setData] = useState<PaymentKeys>({ processor: 'stripe' });
    const [open, setOpen] = useState<boolean>(false);
    const existingKeys = useStoreState(s => s.everest.data!.billing.keys);
    const existingMollie = useStoreState(s => s.everest.data!.billing.mollie);
    const currentProcessor = useStoreState(s => s.everest.data!.billing.processor);

    const submit = async () => {
        if (data.processor === 'stripe') {
            if (!data.stripePublishable || !data.stripeSecret) return;

            await updateSettings('processor', 'stripe');
            await updateSettings('keys:publishable', data.stripePublishable);
            await updateSettings('keys:secret', data.stripeSecret);
            window.location.reload();
        } else if (data.processor === 'mollie') {
            if (!data.mollieApiKey) return;

            await updateSettings('processor', 'mollie');
            await updateSettings('mollie:api_key', data.mollieApiKey);
            window.location.reload();
        }
    };

    useEffect(() => {
        // When extOpen is true (controlled by parent), open the dialog
        if (extOpen === true) {
            setOpen(true);
        }
        // When extOpen is undefined, check if we should auto-open (legacy behavior)
        else if (extOpen === undefined) {
            const isStripeSelected = !currentProcessor || currentProcessor === 'stripe';
            const isMollieSelected = currentProcessor === 'mollie';
            
            const stripeNotConfigured = isStripeSelected && !existingKeys?.publishable;
            const mollieNotConfigured = isMollieSelected && !existingMollie?.api_key;
            
            if (stripeNotConfigured || mollieNotConfigured) {
                setOpen(true);
            }
        }
        // When extOpen is false, do nothing (don't auto-open)
        
        // Set current processor
        if (currentProcessor) {
            setData(prev => ({ ...prev, processor: currentProcessor as 'stripe' | 'mollie' }));
        }
    }, [existingKeys, existingMollie, currentProcessor, extOpen]);

    const isValid = () => {
        if (data.processor === 'stripe') {
            return data.stripePublishable && data.stripeSecret &&
                   data.stripePublishable.length >= 100 && data.stripePublishable.length <= 120 &&
                   data.stripeSecret.length >= 100 && data.stripeSecret.length <= 120;
        } else if (data.processor === 'mollie') {
            return data.mollieApiKey && data.mollieApiKey.length > 10;
        }
        return false;
    };

    return (
        <Dialog open={open} onClose={() => setOpen(false)} title={'Configure Payment Processor'}>
            <div className={'mb-4 rounded-lg bg-black/50 p-3'}>
                <p className={'font-semibold text-gray-200'}>
                    <FontAwesomeIcon icon={faInfoCircle} className={'mr-2 text-blue-400'} />
                    Still setting up?
                </p>
                <p className={'text-sm text-gray-400'}>
                    Feel free to skip this message by closing the dialog and proceed to set up your products and
                    categories. Once you&apos;re ready, head to the Settings tab to input your API keys.
                </p>
            </div>

            <div className={'mb-4'}>
                <Label>Payment Processor</Label>
                <Select
                    value={data.processor}
                    onChange={e => setData({ ...data, processor: e.target.value as 'stripe' | 'mollie' })}
                >
                    <option value="stripe">Stripe</option>
                    <option value="mollie">Mollie</option>
                </Select>
            </div>

            {data.processor === 'stripe' ? (
                <>
                    <p className={'mb-4'}>
                        Before you can use the Stripe API, you must provide Jexactyl with API keys to authenticate
                        with Stripe. Visit the Stripe dashboard
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
                            onChange={e => setData({ ...data, stripePublishable: e.currentTarget.value })}
                        />
                        {!data?.stripePublishable ||
                        data.stripePublishable.length < 100 ||
                        data.stripePublishable.length > 120 ? (
                            <Tooltip
                                placement={'right'}
                                content={'You must enter a valid Stripe publishable key to continue.'}
                            >
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
                            onChange={e => setData({ ...data, stripeSecret: e.currentTarget.value })}
                        />
                        {!data?.stripeSecret || data.stripeSecret.length < 100 || data.stripeSecret.length > 120 ? (
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
                </>
            ) : (
                <>
                    <p className={'mb-4'}>
                        Before you can use the Mollie API, you must provide Jexactyl with your API key. Visit the
                        Mollie dashboard
                        <a
                            target={'_blank'}
                            rel={'noreferrer'}
                            className={'mx-1 text-blue-300'}
                            href={'https://www.mollie.com/dashboard/developers/api-keys'}
                        >
                            here
                        </a>
                        to obtain your API key (starts with &quot;live_&quot; or &quot;test_&quot;), then paste it
                        here.
                    </p>
                    <div className={'relative mt-4'}>
                        <Input
                            placeholder={'Enter Mollie API key here...'}
                            onChange={e => setData({ ...data, mollieApiKey: e.currentTarget.value })}
                        />
                        {!data?.mollieApiKey || data.mollieApiKey.length < 10 ? (
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
                </>
            )}
            <div className={'mt-4 w-full text-right'}>
                <Button onClick={submit} disabled={!isValid()}>
                    Submit
                </Button>
            </div>
        </Dialog>
    );
};
