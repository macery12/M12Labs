import AdminContentBlock from '@/elements/AdminContentBlock';
import DonationsTable from './DonationsTable';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { faHeart, faInfoCircle } from '@fortawesome/free-solid-svg-icons';

export default () => (
    <AdminContentBlock title={'Donations'}>
        <div className={'flex w-full flex-row items-center p-8'}>
            <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                <h2 className={'font-header text-2xl font-medium text-neutral-50'}>
                    <faHeart className={'mr-2'} />
                    Donations
                </h2>
                <p
                    className={
                        'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                    }
                >
                    View all donations made by users on this panel.
                </p>
            </div>
        </div>
        <TitledGreyBox icon={faInfoCircle} title={'About Donations'} className={'mb-8'}>
            Donations are voluntary contributions from users that do not grant any server resources or benefits. All
            donations are processed through Stripe and recorded here for tracking purposes.
        </TitledGreyBox>
        <DonationsTable />
    </AdminContentBlock>
);
