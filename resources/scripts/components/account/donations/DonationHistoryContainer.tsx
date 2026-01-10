import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { getDonations, Donation } from '@/api/routes/account/donations';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { format } from 'date-fns';

const StatusBadge = ({ status }: { status: string }) => {
    const statusColors = {
        completed: 'bg-green-500',
        pending: 'bg-yellow-500',
        failed: 'bg-red-500',
    };

    return (
        <span
            className={`px-2 py-1 rounded text-xs font-semibold text-white ${
                statusColors[status as keyof typeof statusColors] || 'bg-gray-500'
            }`}
        >
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
};

export default () => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [donations, setDonations] = useState<Donation[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        clearFlashes();
        setLoading(true);

        getDonations()
            .then(response => {
                setDonations(response.data);
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'account:donation:history', error });
            })
            .finally(() => {
                setLoading(false);
            });
    }, []);

    return (
        <PageContentBlock title={'Donation History'}>
            <FlashMessageRender byKey={'account:donation:history'} />
            <FlashMessageRender byKey={'account:donation'} />

            <SpinnerOverlay visible={loading} />

            {!loading && donations.length === 0 && (
                <div className={'bg-neutral-800 rounded-lg p-8 text-center'}>
                    <p className={'text-gray-400'}>You haven't made any donations yet.</p>
                </div>
            )}

            {!loading && donations.length > 0 && (
                <div className={'bg-neutral-800 rounded-lg overflow-hidden'}>
                    <table className={'w-full'}>
                        <thead className={'bg-neutral-900'}>
                            <tr>
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
                            </tr>
                        </thead>
                        <tbody className={'divide-y divide-neutral-700'}>
                            {donations.map(donation => (
                                <tr key={donation.id} className={'hover:bg-neutral-700 transition-colors'}>
                                    <td className={'px-6 py-4 whitespace-nowrap text-sm text-gray-300'}>
                                        {format(new Date(donation.created_at), 'MMM dd, yyyy HH:mm')}
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-200'}>
                                        ${donation.amount.toFixed(2)} {donation.currency.toUpperCase()}
                                    </td>
                                    <td className={'px-6 py-4 whitespace-nowrap text-sm'}>
                                        <StatusBadge status={donation.status} />
                                    </td>
                                    <td className={'px-6 py-4 text-sm text-gray-400'}>
                                        {donation.message || <span className={'italic'}>No message</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </PageContentBlock>
    );
};
