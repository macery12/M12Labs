import React, { useEffect, useState } from 'react';
import { format } from 'date-fns';
import { AdminDonation, getAllDonations, PaginatedDonations } from '@/api/routes/admin/billing/donations';
import Pagination from '@/elements/Pagination';
import AdminTable from '@/elements/AdminTable';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faClock, faTimesCircle, faHeart, faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons';

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
            <span className={'text-sm text-gray-500 font-mono'}>
                {revealed ? intentId : maskPaymentIntent(intentId)}
            </span>
            <button
                onClick={() => setRevealed(!revealed)}
                className={'text-gray-400 hover:text-gray-200 transition-colors'}
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
        <div className={`inline-flex items-center px-2 py-1 rounded ${config.bg}`}>
            <FontAwesomeIcon icon={config.icon} className={`${config.color} mr-2 text-xs`} />
            <span className={`${config.color} text-xs font-semibold uppercase`}>{status}</span>
        </div>
    );
};

export default () => {
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
            <div className={'flex justify-center items-center py-12'}>
                <Spinner size={'large'} />
            </div>
        );
    }

    return (
        <>
            <AdminTable>
                <table className={'min-w-full divide-y divide-gray-700'}>
                    <thead className={'bg-neutral-900'}>
                        <tr>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
                                <FontAwesomeIcon icon={faHeart} className={'mr-2'} />
                                Donor
                            </th>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
                                Date
                            </th>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
                                Amount
                            </th>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
                                Status
                            </th>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
                                Message
                            </th>
                            <th className={'px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider'}>
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
                                <tr key={donation.id} className={'hover:bg-neutral-700 transition-colors'}>
                                    <td className={'px-6 py-4 whitespace-nowrap'}>
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
                                                    <div className={'text-sm text-gray-400 italic'}>
                                                        User ID: {donation.user_id}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap text-sm text-gray-300'}>
                                        <div>{format(new Date(donation.created_at), 'MMM dd, yyyy')}</div>
                                        <div className={'text-xs text-gray-500'}>
                                            {format(new Date(donation.created_at), 'HH:mm:ss')}
                                        </div>
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap'}>
                                        <div className={'text-sm font-bold text-green-400'}>
                                            ${donation.amount.toFixed(2)}
                                        </div>
                                        <div className={'text-xs text-gray-500 uppercase'}>{donation.currency}</div>
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap'}>
                                        <StatusBadge status={donation.status} />
                                    </td>
                                    <td className={'px-6 py-4 max-w-xs'}>
                                        {donation.message ? (
                                            <div className={'text-sm text-gray-300 italic truncate'}>
                                                &quot;{donation.message}&quot;
                                            </div>
                                        ) : (
                                            <div className={'text-sm text-gray-500 italic'}>No message</div>
                                        )}
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap'}>
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
                        <div className={'flex justify-center mt-4'}>
                            {isLoading && <Spinner size={'small'} />}
                        </div>
                    )}
                </Pagination>
            )}
        </>
    );
};
