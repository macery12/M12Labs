import { useEffect, useState } from 'react';
import { format } from 'date-fns';
import { getAllDonations, PaginatedDonations } from '@/api/routes/admin/billing/donations';
import Pagination from '@/elements/Pagination';
import AdminTable from '@/elements/AdminTable';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faClock, faTimesCircle, faHeart, faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';

const maskPaymentIntent = (intentId: string): string => {
    if (intentId.length <= 8) return intentId;
    const first4 = intentId.substring(0, 4);
    const last4 = intentId.substring(intentId.length - 4);
    const middle = '•'.repeat(intentId.length - 8);
    return `${first4}${middle}${last4}`;
};

const PaymentIntentDisplay = ({ intentId }: { intentId: string }) => {
    const [revealed, setRevealed] = useState(false);

    return (
        <div className={'flex items-center space-x-2'}>
            <span className={'font-mono text-sm text-gray-500'}>
                {revealed ? intentId : maskPaymentIntent(intentId)}
            </span>
            <button
                onClick={() => setRevealed(!revealed)}
                className={'text-gray-400 transition-colors hover:text-gray-200'}
                title={revealed ? 'Hide' : 'Reveal'}
            >
                <FontAwesomeIcon icon={revealed ? faEyeSlash : faEye} className={'text-xs'} />
            </button>
        </div>
    );
};

const StatusBadge = ({ status }: { status: string }) => {
    const getStatusConfig = () => {
        switch (status) {
            case 'completed':
                return { color: 'text-green-400', bg: 'bg-green-500/20', icon: faCheckCircle };
            case 'pending':
                return { color: 'text-yellow-400', bg: 'bg-yellow-500/20', icon: faClock };
            case 'failed':
                return { color: 'text-red-400', bg: 'bg-red-500/20', icon: faTimesCircle };
            default:
                return { color: 'text-gray-400', bg: 'bg-gray-500/20', icon: faClock };
        }
    };

    const config = getStatusConfig();

    return (
        <div className={`inline-flex items-center rounded px-2 py-1 ${config.bg}`}>
            <FontAwesomeIcon icon={config.icon} className={`${config.color} mr-2 text-xs`} />
            <span className={`${config.color} text-xs font-semibold uppercase`}>{status}</span>
        </div>
    );
};

export default () => {
    const { colors } = useStoreState(state => state.theme.data!);
    const [loading, setLoading] = useState(true);
    const [donations, setDonations] = useState<PaginatedDonations | null>(null);
    const [page, setPage] = useState(1);

    useEffect(() => {
        setLoading(true);
        getAllDonations(page)
            .then(data => setDonations(data))
            .finally(() => setLoading(false));
    }, [page]);

    if (loading && !donations) {
        return (
            <div className={'flex items-center justify-center py-12'}>
                <Spinner size={'large'} />
            </div>
        );
    }

    return (
        <>
            <AdminTable>
                <table className={'min-w-full divide-y divide-gray-700'}>
                    <thead style={{ backgroundColor: colors.background }}>
                        <tr>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                <FontAwesomeIcon icon={faHeart} className={'mr-2'} />
                                Donor
                            </th>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                Date
                            </th>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                Amount
                            </th>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                Status
                            </th>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                Message
                            </th>
                            <th
                                className={
                                    'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400'
                                }
                            >
                                Payment Intent
                            </th>
                        </tr>
                    </thead>
                    <tbody className={'divide-y divide-gray-700'}>
                        {!donations || donations.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className={'px-6 py-8 text-center text-gray-400'}>
                                    No donations found.
                                </td>
                            </tr>
                        ) : (
                            donations.data.map(donation => (
                                <tr key={donation.id} className={'transition-colors hover:bg-neutral-700'}>
                                    <td className={'whitespace-nowrap px-6 py-4'}>
                                        <div className={'flex items-center'}>
                                            <div>
                                                {donation.user ? (
                                                    <>
                                                        <div className={'text-sm font-medium text-gray-200'}>
                                                            {donation.user.username}
                                                        </div>
                                                        <div className={'text-sm text-gray-500'}>
                                                            {donation.user.email}
                                                        </div>
                                                    </>
                                                ) : (
                                                    <div className={'text-sm italic text-gray-400'}>
                                                        User ID: {donation.user_id}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className={'whitespace-nowrap px-6 py-4 text-sm text-gray-300'}>
                                        <div>{format(new Date(donation.created_at), 'MMM dd, yyyy')}</div>
                                        <div className={'text-xs text-gray-500'}>
                                            {format(new Date(donation.created_at), 'HH:mm:ss')}
                                        </div>
                                    </td>
                                    <td className={'whitespace-nowrap px-6 py-4'}>
                                        <div className={'text-sm font-bold text-green-400'}>
                                            ${donation.amount.toFixed(2)}
                                        </div>
                                        <div className={'text-xs uppercase text-gray-500'}>{donation.currency}</div>
                                    </td>
                                    <td className={'whitespace-nowrap px-6 py-4'}>
                                        <StatusBadge status={donation.status} />
                                    </td>
                                    <td className={'max-w-xs px-6 py-4'}>
                                        {donation.message ? (
                                            <div className={'truncate text-sm italic text-gray-300'}>
                                                &quot;{donation.message}&quot;
                                            </div>
                                        ) : (
                                            <div className={'text-sm italic text-gray-500'}>No message</div>
                                        )}
                                    </td>
                                    <td className={'whitespace-nowrap px-6 py-4'}>
                                        <PaymentIntentDisplay intentId={donation.payment_intent_id} />
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </AdminTable>
            {donations && donations.last_page > 1 && (
                <Pagination data={donations} onPageSelect={setPage}>
                    {({ isLoading }) => (
                        <div className={'mt-4 flex justify-center'}>{isLoading && <Spinner size={'small'} />}</div>
                    )}
                </Pagination>
            )}
        </>
    );
};
