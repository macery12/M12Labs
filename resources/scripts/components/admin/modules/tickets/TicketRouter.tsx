import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { NotFound } from '@/elements/ScreenBlock';
import NewTicketForm from '@admin/modules/tickets/NewTicketForm';
import TicketsContainer from '@admin/modules/tickets/TicketsContainer';
import ViewTicketContainer from '@admin/modules/tickets/view/ViewTicketContainer';
import EnableTicketsContainer from './EnableTicketsContainer';
import { CogIcon, TicketIcon } from '@heroicons/react/outline';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import TicketOptionsContainer from './TicketOptionsContainer';

export default () => {
    const enabled = useStoreState(state => state.everest.data!.tickets.enabled);

    if (!enabled) return <EnableTicketsContainer />;

    return (
        <AdminContentBlock title={'Ticket Dashboard'}>
            <FlashMessageRender byKey={'admin:tickets'} className={'mb-4'} />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Ticket Dashboard</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        View, create and update tickets to users for support.
                    </p>
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to={'/admin/tickets'} name={'Open Tickets'} base>
                    <TicketIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/tickets/options'} name={'Options'}>
                    <CogIcon />
                </SubNavigationLink>
            </SubNavigation>
            <Routes>
                <Route path={'/'} element={<TicketsContainer />} />

                <Route path={'/new'} element={<NewTicketForm />} />
                <Route path={'/:id'} element={<ViewTicketContainer />} />

                <Route path={'/options'} element={<TicketOptionsContainer />} />

                <Route path={'/*'} element={<NotFound />} />
            </Routes>
        </AdminContentBlock>
    );
};
