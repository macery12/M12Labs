import { Dispatch, SetStateAction, useState } from 'react';
import { faDollar, faPercentage, faPlus } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Dialog } from '@/elements/dialog';
import Input from '@/elements/Input';
import { Button } from '@/elements/button';
import { validateDiscountCode } from '@/api/routes/account/billing/discount-codes';
import { DiscountCode } from '@/api/definitions/account/billing';
import { useStoreState } from '@/state/hooks';

interface Props {
    discountCode?: DiscountCode;
    setDiscountCode: Dispatch<SetStateAction<DiscountCode | undefined>>;
}

export default ({ discountCode, setDiscountCode }: Props) => {
    const currencySymbol = useStoreState(state => state.everest.data!.billing.currency.symbol);

    const [open, setOpen] = useState<boolean>(false);
    const [input, setInput] = useState<string>('');
    const [invalid, setInvalid] = useState<boolean>(false);

    const submit = () => {
        setInvalid(false);

        if (input?.length >= 4) {
            validateDiscountCode(input)
                .then(setDiscountCode)
                .catch(() => setInvalid(true));
        }
    };

    return (
        <>
            <Dialog open={open} onClose={() => setOpen(false)} title={'Apply Discount Code'}>
                If you have a discount code for this order, you can apply it here.
                {!discountCode ? (
                    <div className={'inline-flex'}>
                        <Input className={'mt-4'} placeholder={'SAVE20'} onChange={e => setInput(e.target.value)} />
                        <Button size={Button.Sizes.Small} className={'my-auto mt-5 ml-2'} onClick={submit}>
                            Validate
                        </Button>
                    </div>
                ) : (
                    <div className={'mt-4'}>
                        <div className={'flex bg-black/50 p-4 rounded-lg'}>
                            <FontAwesomeIcon
                                fixedWidth
                                className={'mx-3 my-auto bg-black rounded-full p-2'}
                                icon={discountCode.type === 'percentage' ? faPercentage : faDollar}
                            />
                            <div>
                                <p className={'text-lg font-bold'}>
                                    {discountCode.code}
                                    <span className={'ml-4 text-sm text-green-400 font-semibold my-auto'}>
                                        {discountCode.value}
                                        {discountCode.type === 'percentage' ? '%' : currencySymbol}
                                        &nbsp;saving
                                    </span>
                                </p>
                                <p className={'mt-1 text-sm text-gray-400'}>{discountCode.description}</p>
                            </div>
                        </div>
                        <div className={'text-right mt-4'}>
                            <Button onClick={() => setOpen(false)}>Done</Button>
                        </div>
                    </div>
                )}
                {invalid && (
                    <p className={'text-red-400 text-sm'}>This discount code is not valid and cannot be used.</p>
                )}
            </Dialog>
            <div className={'font-semibold text-blue-400 my-auto mr-4'} onClick={() => setOpen(true)}>
                Add a discount code <FontAwesomeIcon icon={faPlus} className={'ml-2'} />
            </div>
        </>
    );
};
