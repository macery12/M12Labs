import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { getDonations, Donation } from '@/api/routes/account/donations';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { format } from 'date-fns';
import ContentBox from '@/elements/ContentBox';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faHeart,
    faCalendar,
    faDollarSign,
    faMessage,
    faCheckCircle,
    faClock,
    faTimesCircle,
    faUser,
} from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';

const StatusBadge = ({ status }: { status: string }) => {
    const getStatusConfig = () => {
        switch (status) {
            case 'completed':
                return {
                    color: 'text-green-400',
                    bg: 'bg-green-500/20',
                    border: 'border-green-500/50',
                    icon: faCheckCircle,
                };
            case 'pending':
                return {
                    color: 'text-yellow-400',
                    bg: 'bg-yellow-500/20',
                    border: 'border-yellow-500/50',
                    icon: faClock,
                };
            case 'failed':
                return { color: 'text-red-400', bg: 'bg-red-500/20', border: 'border-red-500/50', icon: faTimesCircle };
            default:
                return { color: 'text-gray-400', bg: 'bg-gray-500/20', border: 'border-gray-500/50', icon: faClock };
        }
    };

    const config = getStatusConfig();

    return (
        <div
            css={tw`inline-flex items-center px-3 py-1 rounded-full border`}
            className={`${config.bg} ${config.border}`}
        >
            <FontAwesomeIcon icon={config.icon} className={config.color} css={tw`mr-2 text-xs`} />
            <span className={config.color} css={tw`text-xs font-semibold uppercase`}>
                {status}
            </span>
        </div>
    );
};

export default () => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);
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

    const totalDonated = donations.filter(d => d.status === 'completed').reduce((sum, d) => sum + d.amount, 0);

    return (
        <PageContentBlock title={'Donation History'}>
            <FlashMessageRender byKey={'account:donation:history'} />
            <FlashMessageRender byKey={'account:donation'} />

            <div css={tw`mt-8 mb-8 flex items-center justify-between`}>
                <div>
                    <h1 css={tw`text-3xl font-bold lg:text-5xl`}>
                        <FontAwesomeIcon icon={faHeart} css={tw`mr-3`} style={{ color: colors.primary }} />
                        Your Contributions
                    </h1>
                    <p css={tw`mt-2 text-sm font-normal text-gray-400`}>Thank you for supporting our platform!</p>
                </div>
                {totalDonated > 0 && (
                    <div css={tw`text-right`}>
                        <p css={tw`text-sm text-gray-400`}>Total Donated</p>
                        <p css={tw`text-3xl font-bold`} style={{ color: colors.primary }}>
                            ${totalDonated.toFixed(2)}
                        </p>
                    </div>
                )}
            </div>

            <SpinnerOverlay visible={loading} />

            {!loading && donations.length === 0 && (
                <ContentBox>
                    <div css={tw`text-center py-12`}>
                        <FontAwesomeIcon icon={faHeart} css={tw`text-6xl text-gray-600 mb-4`} />
                        <p css={tw`text-xl font-semibold text-gray-300 mb-2`}>No donations yet</p>
                        <p css={tw`text-gray-400 mb-6`}>You haven't made any donations. Support us today!</p>
                        <a
                            href="/account/donations"
                            css={tw`inline-block px-6 py-3 rounded font-semibold text-white transition-all hover:brightness-110`}
                            style={{ backgroundColor: colors.primary }}
                        >
                            <FontAwesomeIcon icon={faHeart} css={tw`mr-2`} />
                            Make a Donation
                        </a>
                    </div>
                </ContentBox>
            )}

            {!loading && donations.length > 0 && (
                <div css={tw`space-y-4`}>
                    {donations.map(donation => (
                        <ContentBox key={donation.id}>
                            <div css={tw`grid grid-cols-1 md:grid-cols-4 gap-4`}>
                                <div css={tw`flex items-center`}>
                                    <div
                                        css={tw`w-12 h-12 rounded-full flex items-center justify-center mr-4`}
                                        style={{
                                            backgroundColor: colors.primary + '20',
                                            border: `2px solid ${colors.primary}`,
                                        }}
                                    >
                                        <FontAwesomeIcon icon={faHeart} style={{ color: colors.primary }} />
                                    </div>
                                    <div>
                                        {donation.user && (
                                            <p css={tw`text-sm font-semibold text-gray-200 mb-1 flex items-center`}>
                                                <FontAwesomeIcon icon={faUser} css={tw`mr-1`} />
                                                {donation.user.username}
                                            </p>
                                        )}
                                        <p css={tw`text-xs text-gray-400 flex items-center`}>
                                            <FontAwesomeIcon icon={faCalendar} css={tw`mr-1`} />
                                            {format(new Date(donation.created_at), 'MMM dd, yyyy')}
                                        </p>
                                        <p css={tw`text-xs text-gray-500`}>
                                            {format(new Date(donation.created_at), 'HH:mm:ss')}
                                        </p>
                                    </div>
                                </div>

                                <div css={tw`flex items-center`}>
                                    <div>
                                        <p css={tw`text-xs text-gray-400 mb-1 flex items-center`}>
                                            <FontAwesomeIcon icon={faDollarSign} css={tw`mr-1`} />
                                            Amount
                                        </p>
                                        <p css={tw`text-2xl font-bold`} style={{ color: colors.primary }}>
                                            ${donation.amount.toFixed(2)}
                                        </p>
                                        <p css={tw`text-xs text-gray-500 uppercase`}>{donation.currency}</p>
                                    </div>
                                </div>

                                <div css={tw`flex items-center`}>
                                    <StatusBadge status={donation.status} />
                                </div>

                                <div css={tw`flex items-start md:items-center`}>
                                    {donation.message ? (
                                        <div css={tw`w-full`}>
                                            <p css={tw`text-xs text-gray-400 mb-1 flex items-center`}>
                                                <FontAwesomeIcon icon={faMessage} css={tw`mr-1`} />
                                                Message
                                            </p>
                                            <p css={tw`text-sm text-gray-300 italic line-clamp-2`}>
                                                &quot;{donation.message}&quot;
                                            </p>
                                        </div>
                                    ) : (
                                        <p css={tw`text-sm text-gray-500 italic`}>No message</p>
                                    )}
                                </div>
                            </div>
                        </ContentBox>
                    ))}
                </div>
            )}
        </PageContentBlock>
    );
};
