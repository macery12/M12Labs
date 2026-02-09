import { useStoreState } from '@/state/hooks';
import ContentBox from '@/elements/ContentBox';
import { differenceInDays, parseISO } from 'date-fns';
import { BillingAnalytics } from '@definitions/admin';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faExclamationTriangle, faTimesCircle } from '@fortawesome/free-solid-svg-icons';
import { faCcStripe, faCcPaypal } from '@fortawesome/free-brands-svg-icons';
import { faCircle } from '@fortawesome/free-solid-svg-icons';

interface BillingHealthSummaryProps {
    data: BillingAnalytics;
    history: number;
}

export default ({ data, history }: BillingHealthSummaryProps) => {
    const now = new Date();
    const settings = useStoreState(s => s.everest.data!.billing);

    // Calculate failed payments count
    const failedPayments = data.orders.filter(
        x => x.status === 'failed' && differenceInDays(now, parseISO(x.created_at.toString())) <= history,
    ).length;

    // Payment provider status
    const providers = [
        {
            name: 'Stripe',
            icon: faCcStripe,
            enabled: settings.processors?.stripe?.enabled || false,
            configured: settings.keys?.publishable && settings.keys?.secret,
        },
        {
            name: 'Mollie',
            icon: faCircle,
            enabled: settings.processors?.mollie?.enabled || false,
            configured: settings.mollie?.api_key,
        },
        {
            name: 'PayPal',
            icon: faCcPaypal,
            enabled: settings.processors?.paypal?.enabled || false,
            configured: settings.processors?.paypal?.available || false,
            mode: settings.paypal_standalone?.mode,
        },
    ];

    const getProviderStatus = (provider: (typeof providers)[0]) => {
        // Special handling for PayPal - ignore webhook status, only check API key and mode
        if (provider.name === 'PayPal') {
            if (!provider.enabled) {
                return { icon: faTimesCircle, color: 'text-gray-500', label: 'Disabled' };
            }
            if (provider.configured && provider.mode === 'live') {
                return { icon: faCheckCircle, color: 'text-green-500', label: 'Active' };
            }
            if (provider.configured && provider.mode === 'sandbox') {
                return { icon: faExclamationTriangle, color: 'text-yellow-500', label: 'Sandbox Mode' };
            }
            if (provider.configured && !provider.mode) {
                return { icon: faExclamationTriangle, color: 'text-yellow-500', label: 'Partially Configured' };
            }
            return { icon: faTimesCircle, color: 'text-gray-500', label: 'Not Configured' };
        }

        // Default logic for other providers
        if (provider.enabled && provider.configured) {
            return { icon: faCheckCircle, color: 'text-green-500', label: 'Active' };
        } else if (provider.enabled && !provider.configured) {
            return { icon: faExclamationTriangle, color: 'text-yellow-500', label: 'Partially Configured' };
        } else {
            return { icon: faTimesCircle, color: 'text-gray-500', label: 'Disabled' };
        }
    };

    return (
        <ContentBox title="Billing Health" className="min-h-[160px]">
            <div className="space-y-4">
                {/* Billing Status */}
                <div className="flex items-center justify-between">
                    <span className="text-sm text-gray-400">Billing Module</span>
                    <div className="flex items-center gap-2">
                        <FontAwesomeIcon
                            icon={settings.enabled ? faCheckCircle : faTimesCircle}
                            className={settings.enabled ? 'text-green-500' : 'text-gray-500'}
                        />
                        <span className="text-sm font-medium">{settings.enabled ? 'Enabled' : 'Disabled'}</span>
                    </div>
                </div>

                {/* Payment Providers */}
                <div>
                    <h3 className="mb-2 text-sm font-medium text-gray-300">Payment Providers</h3>
                    <div className="space-y-2">
                        {providers.map(provider => {
                            const status = getProviderStatus(provider);
                            return (
                                <div key={provider.name} className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <FontAwesomeIcon icon={provider.icon} className="text-gray-400" />
                                        <span className="text-sm text-gray-400">{provider.name}</span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <FontAwesomeIcon icon={status.icon} className={status.color} />
                                        <span className={`text-xs ${status.color}`}>{status.label}</span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Failed Payments */}
                <div className="rounded border border-red-500/20 bg-red-500/10 p-3">
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-400">Failed Payments</span>
                        <span className="text-xl font-bold text-red-400">{failedPayments}</span>
                    </div>
                    <p className="mt-1 text-xs text-gray-500">In the last {history} days</p>
                </div>
            </div>
        </ContentBox>
    );
};
