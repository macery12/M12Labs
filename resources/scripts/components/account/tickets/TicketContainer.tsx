import { useEffect } from 'react';
import { useFlashKey } from '@/plugins/useFlash';
import { useTickets } from '@/api/routes/account/tickets';
import ContentBox from '@/elements/ContentBox';
import FlashMessageRender from '@/elements/FlashMessageRender';
import PageContentBlock from '@/elements/PageContentBlock';
import CreateTicketForm from '@account/tickets/CreateTicketForm';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { format, formatDistanceToNow } from 'date-fns';
import classNames from 'classnames';
import { Link } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';

export const statusToColor = (status: string): string => {
    switch (status) {
        case 'in-progress':
            return 'bg-yellow-200 text-yellow-800';
        case 'unresolved':
            return 'bg-red-200 text-red-800';
        case 'resolved':
            return 'bg-green-200 text-green-800';
        default:
            return 'bg-gray-400 text-gray-800';
    }
};

export default () => {
    const { clearAndAddHttpError } = useFlashKey('account');
    const { colors } = useStoreState(state => state.theme.data!);
    const {
        data: tickets,
        isValidating,
        error,
    } = useTickets({
        revalidateOnMount: true,
        revalidateOnFocus: false,
    });

    useEffect(() => {
        clearAndAddHttpError(error);
    }, [error]);

    return (
        <PageContentBlock title={'Support Tickets'}>
            <FlashMessageRender byKey={'account:tickets'} />
            <div className={'mt-8 mb-12 text-3xl font-bold lg:text-5xl'}>
                Your Support Tickets
                <p className={'mt-1 text-sm font-normal text-gray-400'}>
                    Create a ticket to gain support from an administrator.
                </p>
            </div>
            <div className={'grid gap-4 lg:grid-cols-3'}>
                <div className={'lg:col-span-2'}>
                    <ContentBox title={'Support Tickets'}>
                        <SpinnerOverlay visible={!tickets && isValidating} />
                        {!tickets || !tickets.length ? (
                            <p className={'text-center text-sm'}>
                                {!tickets ? 'Loading...' : 'No tickets exist for this account.'}
                            </p>
                        ) : (
                            tickets.map((ticket, index) => (
                                <Link to={`/account/tickets/${ticket.id}`} key={ticket.id}>
                                    <div
                                        style={{ backgroundColor: colors.headers }}
                                        className={classNames(index > 0 ? 'mt-4' : 'mt-0', 'flex rounded-lg p-4')}
                                    >
                                        <div className={'flex w-full items-center truncate md:flex-1'}>
                                            <p className={'mr-4 text-xl font-bold'}>#{ticket.id}</p>
                                            <div className={'flex flex-col truncate'}>
                                                <div className={'mb-1 flex items-center'}>
                                                    <p className={'truncate break-words text-lg'}>{ticket.title}</p>
                                                    <span
                                                        className={classNames(
                                                            statusToColor(ticket.status),
                                                            'ml-4 inline-flex hidden rounded-full px-2 text-xs font-medium capitalize leading-5 sm:inline',
                                                        )}
                                                    >
                                                        {ticket.status}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        {ticket.createdAt && (
                                            <div
                                                className={
                                                    'mt-4 flex-1 md:mt-0 md:ml-8 md:w-48 md:flex-none md:text-center'
                                                }
                                            >
                                                <p className={'text-sm'}>{format(ticket.createdAt, 'MMMM do, yyyy')}</p>
                                                <p className={'mt-1 text-2xs uppercase text-gray-300'}>
                                                    Created{' '}
                                                    {formatDistanceToNow(ticket.createdAt, {
                                                        includeSeconds: true,
                                                        addSuffix: true,
                                                    })}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </Link>
                            ))
                        )}
                    </ContentBox>
                </div>
                <ContentBox title={'Create Ticket'}>
                    <CreateTicketForm />
                </ContentBox>
            </div>
        </PageContentBlock>
    );
};
