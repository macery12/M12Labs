import { useStoreState } from '@/state/hooks';
import CancelSvg from '@/assets/images/themed/CancelSvg';
import PageContentBlock from '@/elements/PageContentBlock';

export default () => {
    const { colors } = useStoreState(s => s.theme.data!);

    return (
        <PageContentBlock>
            <div className={'flex justify-center'}>
                <div
                    className={'relative w-full rounded-lg p-12 text-center shadow-lg sm:w-3/4 md:w-1/2 md:p-20'}
                    style={{ backgroundColor: colors.secondary }}
                >
                    <CancelSvg color={colors.primary} />
                    <h2 className={'mt-10 text-4xl font-bold text-white'}>Order Cancelled</h2>
                    <p className={'mt-2 text-sm text-neutral-400'}>
                        Your order was cancelled due to payment not being submitted to Stripe. You have not been
                        charged. If you&apos;d like to retry this order, please click &apos;Order&apos; above.
                    </p>
                </div>
            </div>
        </PageContentBlock>
    );
};
