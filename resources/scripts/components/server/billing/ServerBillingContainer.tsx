import { useEffect, useState } from 'react';
import Label from '@/elements/Label';
import { Link, useNavigate } from 'react-router-dom';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { ServerContext } from '@/state/server';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faArrowRight,
    faClock,
    faCreditCard,
    faInfoCircle,
    faBox,
    faCalendarAlt,
    faExclamationTriangle,
} from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Alert } from '@/elements/alert';
import PaymentContainer from './PaymentContainer';
import ChangeEggContainer from './ChangeEggContainer';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import { getProduct } from '@/api/routes/account/billing/products';
import { Product } from '@definitions/account/billing';
import { renewFreeServer } from '@/api/routes/account/billing/orders/process';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import tw from 'twin.macro';

function timeUntil(targetDate: Date | string) {
    const date = targetDate instanceof Date ? targetDate : new Date(targetDate);

    const now = new Date();
    const diffMs = date.getTime() - now.getTime();

    return {
        days: Math.floor(diffMs / (1000 * 60 * 60 * 24)),
        hours: Math.floor((diffMs / (1000 * 60 * 60)) % 24),
    };
}

function getRenewalStatusBadge(
    daysRemaining: number,
    suspensionThreshold: number,
    daysOverdue: number,
    freeGraceDays: number,
) {
    if (daysRemaining < 0) {
        if (daysOverdue > freeGraceDays) {
            return { text: 'Overdue', color: 'bg-red-500', icon: faExclamationTriangle };
        }
        return { text: 'Grace Period', color: 'bg-yellow-500', icon: faClock };
    } else if (daysRemaining <= suspensionThreshold) {
        return { text: 'Renewal Available', color: 'bg-yellow-500', icon: faClock };
    }
    return { text: 'Active', color: 'bg-green-500', icon: faInfoCircle };
}

export default () => {
    const [product, setProduct] = useState<Product>();
    const [loading, setLoading] = useState<boolean>(true);
    const [renewing, setRenewing] = useState<boolean>(false);

    const navigate = useNavigate();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const settings = useStoreState(s => s.everest.data!.billing);
    const serverUuid = ServerContext.useStoreState(s => s.server.data!.uuid);
    const serverId = ServerContext.useStoreState(s => s.server.data!.internalId);
    const billingProductId = ServerContext.useStoreState(s => s.server.data!.billingProductId);
    const renewalDate = ServerContext.useStoreState(s => s.server.data!.renewalDate);

    // Get configurable renewal settings
    const renewalDays = settings.renewal?.days || 30;
    const freeRenewalDays = settings.renewal?.free_renewal_days || 30;
    const freeGraceDays = settings.renewal?.free_suspension_days || 7;
    const suspensionThreshold = settings.renewal?.suspension_threshold || 7;

    useEffect(() => {
        clearFlashes();

        if (billingProductId) {
            getProduct(billingProductId)
                .then(data => setProduct(data))
                .then(() => setLoading(false))
                .catch(error => {
                    setLoading(false);
                    console.error(error);
                });
        }
    }, []);

    const handleFreeRenewal = () => {
        if (!product || !billingProductId) return;

        setRenewing(true);
        clearFlashes('server:billing');

        renewFreeServer(billingProductId, serverId)
            .then(() => {
                // Redirect to server overview after successful renewal
                navigate(`/server/${serverUuid}`);
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'server:billing', error });
                setRenewing(false);
            });
    };

    // Calculate days remaining until renewal (can be negative if overdue)
    const daysRemaining = renewalDate ? timeUntil(renewalDate).days : 0;
    const daysOverdue = daysRemaining < 0 ? Math.abs(daysRemaining) : 0;

    const statusBadge = renewalDate
        ? getRenewalStatusBadge(daysRemaining, suspensionThreshold, daysOverdue, freeGraceDays)
        : null;

    return (
        <PageContentBlock
            title={'Server Billing'}
            header
            description={'Manage your server subscription, renewal, and billing settings.'}
        >
            <FlashMessageRender byKey={'server:billing'} css={tw`mb-4`} />
            {!product && !loading && (
                <Alert type={'warning'} className={'mb-6'}>
                    The product package you purchased initially no longer exists, so some details may not be shown.
                </Alert>
            )}

            {/* Billing Overview Section */}
            <div css={tw`grid gap-4 md:grid-cols-2 lg:grid-cols-3 mb-4`}>
                {/* Server Status Card */}
                {renewalDate && (
                    <TitledGreyBox title={'Renewal Status'} icon={faClock}>
                        <SpinnerOverlay visible={loading} />
                        {statusBadge && (
                            <div css={tw`mb-4`}>
                                <span
                                    css={tw`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium text-white`}
                                    className={statusBadge.color}
                                >
                                    <FontAwesomeIcon icon={statusBadge.icon} css={tw`mr-2`} />
                                    {statusBadge.text}
                                </span>
                            </div>
                        )}
                        <div>
                            <Label>Next Renewal Date</Label>
                            <p css={tw`text-gray-300 font-medium mb-1`}>
                                {new Date(renewalDate).toLocaleDateString('en-US', {
                                    weekday: 'long',
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}
                            </p>
                            <p css={tw`text-gray-400 text-sm`}>
                                <FontAwesomeIcon icon={faClock} css={tw`mr-1`} />
                                {daysRemaining >= 0 ? (
                                    <>
                                        {daysRemaining} {daysRemaining === 1 ? 'day' : 'days'},{' '}
                                        {timeUntil(renewalDate).hours}{' '}
                                        {timeUntil(renewalDate).hours === 1 ? 'hour' : 'hours'} remaining
                                    </>
                                ) : (
                                    <>
                                        {daysOverdue} {daysOverdue === 1 ? 'day' : 'days'} overdue
                                    </>
                                )}
                            </p>
                        </div>
                    </TitledGreyBox>
                )}

                {/* Package Information Card */}
                <TitledGreyBox title={'Package Details'} icon={faBox}>
                    <SpinnerOverlay visible={loading} />
                    <div>
                        <Label>Current Package</Label>
                        <p css={tw`text-gray-300 font-medium mb-1`}>
                            {product ? product.name : loading ? 'Loading...' : 'Unknown'}
                        </p>
                        {product?.description && <p css={tw`text-gray-400 text-xs mb-3`}>{product.description}</p>}
                    </div>
                    <div css={tw`mt-4`}>
                        <Label>Billing Cycle</Label>
                        <p css={tw`text-gray-400 text-sm`}>
                            <FontAwesomeIcon icon={faCalendarAlt} css={tw`mr-1`} />
                            Every {renewalDays} days
                        </p>
                    </div>
                </TitledGreyBox>

                {/* Pricing Card */}
                <TitledGreyBox title={'Plan Cost'} icon={faCreditCard}>
                    <SpinnerOverlay visible={loading} />
                    <div>
                        <Label>Price</Label>
                        <p css={tw`text-3xl font-bold text-gray-200 mb-2`}>
                            {settings.currency.symbol}
                            {product ? product.price : '...'}
                            <span css={tw`text-sm font-normal text-gray-400 ml-1`}>
                                {settings.currency.code.toUpperCase()}
                            </span>
                        </p>
                        <p css={tw`text-gray-400 text-xs mb-3`}>per {renewalDays} day billing cycle</p>
                        <Link
                            to={'/account/billing/orders'}
                            css={tw`text-green-400 text-sm hover:text-green-300 transition-colors duration-150`}
                        >
                            View order history <FontAwesomeIcon icon={faArrowRight} css={tw`ml-1`} />
                        </Link>
                    </div>
                </TitledGreyBox>
            </div>

            {!renewalDate && (
                <Alert type={'warning'} className={'mb-4'}>
                    There is no present renewal date for your server. Please contact support if you believe this is an
                    error.
                </Alert>
            )}

            {/* Renewal Section */}
            <TitledGreyBox title={'Server Renewal'} icon={faCreditCard} css={tw`mb-4`}>
                {!product ? (
                    <Alert type={'danger'}>
                        The product package that the server was made with no longer exists. In order to renew your
                        server, you&apos;ll need to speak to an administrator.
                    </Alert>
                ) : (
                    <>
                        {product.price === 0 ? (
                            <div>
                                <div
                                    css={tw`bg-blue-900 bg-opacity-25 border border-blue-500 border-opacity-50 rounded p-4 mb-4`}
                                >
                                    <p css={tw`text-blue-200 text-sm flex items-start`}>
                                        <FontAwesomeIcon icon={faInfoCircle} css={tw`mr-2 mt-0.5`} />
                                        <span>
                                            This is a free server. You can renew it for another{' '}
                                            <strong>{freeRenewalDays} days</strong> starting{' '}
                                            <strong>{suspensionThreshold} days</strong> before it expires. You can also
                                            renew within the <strong>{freeGraceDays}-day</strong> grace period after
                                            expiration.
                                        </span>
                                    </p>
                                </div>
                                {daysOverdue > freeGraceDays ? (
                                    <Alert type={'danger'}>
                                        <strong>Server Expired</strong>
                                        <p css={tw`mt-1`}>
                                            This server has been overdue for more than {freeGraceDays} days and can no
                                            longer be renewed through self-service. Please contact support for
                                            assistance.
                                        </p>
                                    </Alert>
                                ) : daysRemaining > suspensionThreshold ? (
                                    <Alert type={'info'}>
                                        <strong>Renewal Not Yet Available</strong>
                                        <p css={tw`mt-1`}>
                                            You still have {daysRemaining} days before your server expires. The renew
                                            button will become available {suspensionThreshold} days before expiration.
                                        </p>
                                    </Alert>
                                ) : (
                                    <div>
                                        <p css={tw`text-gray-300 text-sm mb-4`}>
                                            {daysRemaining >= 0 ? (
                                                <>
                                                    Your server will expire in {daysRemaining} days. Click below to
                                                    renew for another {freeRenewalDays} days.
                                                </>
                                            ) : (
                                                <>
                                                    Your server is currently in the grace period ({daysOverdue}/
                                                    {freeGraceDays} days). Renew now to restore full functionality.
                                                </>
                                            )}
                                        </p>
                                        <Button
                                            onClick={handleFreeRenewal}
                                            disabled={renewing}
                                            size={Button.Sizes.Large}
                                            css={tw`w-full sm:w-auto`}
                                        >
                                            {renewing ? 'Renewing Server...' : `Renew for ${freeRenewalDays} Days`}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div>
                                <p css={tw`text-gray-300 text-sm mb-4`}>
                                    Complete the payment below to renew your server for another {renewalDays} days.
                                </p>
                                <PaymentContainer id={Number(product.id)} />
                            </div>
                        )}
                    </>
                )}
            </TitledGreyBox>

            {/* Change Server Type Section */}
            <ChangeEggContainer />
        </PageContentBlock>
    );
};
