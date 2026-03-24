import { Product } from '@/api/definitions/account/billing';
import { getUpgradeCharge, getUpgradeOptions, processUpgrade } from '@/api/routes/server/billing';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import ContentBox from '@/elements/ContentBox';
import { Dialog } from '@/elements/dialog';
import FlashMessageRender from '@/elements/FlashMessageRender';
import PageContentBlock from '@/elements/PageContentBlock';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import { ServerContext } from '@/state/server';
import {
    faShoppingBag,
    faMicrochip,
    faMemory,
    faHdd,
    faArchive,
    faDatabase,
    faEthernet,
    IconDefinition,
    faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { ReactElement, useEffect, useState } from 'react';

interface LimitProps {
    icon: IconDefinition;
    limit: ReactElement;
}

const LimitBox = ({ icon, limit }: LimitProps) => (
    <div className={'text-gray-400 mt-1'}>
        <FontAwesomeIcon icon={icon} className={'w-4 h-4 mr-2'} />
        {limit}
    </div>
);

export default () => {
    const settings = useStoreState(state => state.everest.data!.billing);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);
    const server = ServerContext.useStoreState(state => state.server.data!);

    const [open, setOpen] = useState<Product | null>();
    const [options, setOptions] = useState<Product[]>();
    const [charge, setCharge] = useState<number | null>();

    useEffect(() => {
        clearFlashes();

        getUpgradeOptions(server.id)
            .then(setOptions)
            .then(() => console.log(options))
            .catch(error => clearAndAddHttpError({ key: 'server:billing:upgrade', error }));
    }, []);

    useEffect(() => {
        if (open) {
            getUpgradeCharge(server.id, open.id)
                .then(data => setCharge(data))
                .catch(error => clearAndAddHttpError({ key: 'server:billing:upgrade', error }));
        }
    }, [open]);

    const submit = () => {
        if (open) {
            processUpgrade(server.id, open.id)
                .then(url => window.location.assign(url))
                .catch(error => clearAndAddHttpError({ key: 'server:billing:upgrade', error }));
        }
    };

    return (
        <PageContentBlock
            title={'Upgrade Options'}
            header
            description={'View your current product and upgrade to a new option.'}
        >
            {open && (
                <Dialog
                    open
                    onClose={() => setOpen(null)}
                    title={`Confirm Upgrade to ${open.name} - ${settings.currency.symbol}${open.price} / mo`}
                >
                    To upgrade in between your billing cycle, you must pay a one off charge specified below. Your server
                    will then instantly be upgraded to the selected package, and will renew at the new package monthly
                    cost.
                    <div className={'my-3 w-full'}>
                        <code className={'px-2 py-1 w-full bg-black/50 rounded-lg'}>
                            {charge !== null ? (
                                <>
                                    {settings.currency.symbol}
                                    {charge?.toFixed(2)} {settings.currency.code.toUpperCase()}
                                </>
                            ) : (
                                <FontAwesomeIcon icon={faSpinner} className={'animate-spin'} />
                            )}
                        </code>
                        <span className={'ml-2 italic text-gray-400'}>one-time charge to upgrade early, taken now</span>
                    </div>
                    Then, from {new Date(server.renewalDate!).toLocaleDateString()}, your renewal cost will be{' '}
                    {settings.currency.symbol}
                    {open.price}/mo.
                    <div className={'mt-4 text-right'}>
                        <Button onClick={submit} disabled={!charge}>
                            Upgrade Now
                        </Button>
                    </div>
                </Dialog>
            )}
            <FlashMessageRender byKey={'server:billing:upgrade'} />
            <div className={'grid grid-cols-1 xl:grid-cols-3 gap-4'}>
                {!options ||
                    (options.length === 0 && (
                        <Alert type={'info'} className={'xl:col-span-3'}>
                            There are no packages available to upgrade to. If you wish to upgrade, please speak to an
                            administrator.
                        </Alert>
                    ))}
                {options?.map(product => (
                    <ContentBox key={product.id}>
                        <div className={'p-3 lg:p-6'}>
                            <div className={'flex justify-center'}>
                                {product.icon ? (
                                    <img src={product.icon} className={'w-16 h-16'} />
                                ) : (
                                    <FontAwesomeIcon
                                        icon={faShoppingBag}
                                        className={'w-12 h-12 m-2'}
                                        style={{ color: colors.primary }}
                                    />
                                )}
                            </div>
                            <p className={'text-3xl font-bold text-center mt-3'}>{product.name}</p>
                            <p className={'text-lg font-semibold text-center mt-1 mb-4 text-gray-400'}>
                                <span style={{ color: colors.primary }} className={'mr-1'}>
                                    {settings.currency.symbol}
                                    {product.price.toFixed(2)}
                                    &nbsp;
                                    {settings.currency.code.toUpperCase()}
                                </span>
                                <span className={'text-base'}>/ monthly</span>
                            </p>
                            <div className={'grid justify-center items-center'}>
                                <LimitBox icon={faMicrochip} limit={<>{product.limits.cpu}% CPU</>} />
                                <LimitBox icon={faMemory} limit={<>{product.limits.memory / 1024} GiB of RAM</>} />
                                <LimitBox icon={faHdd} limit={<>{product.limits.disk / 1024} GiB of Storage</>} />
                                <div className={'border border-dashed border-gray-500 my-4'} />
                                {product.limits.backup ? (
                                    <LimitBox icon={faArchive} limit={<>{product.limits.backup} backup slots</>} />
                                ) : (
                                    <></>
                                )}
                                {product.limits.database ? (
                                    <LimitBox icon={faDatabase} limit={<>{product.limits.database} database slots</>} />
                                ) : (
                                    <></>
                                )}
                                <LimitBox
                                    icon={faEthernet}
                                    limit={
                                        <>
                                            {product.limits.allocation} network port
                                            {product.limits.allocation > 1 && 's'}
                                        </>
                                    }
                                />
                            </div>
                            <div className={'text-center mt-6'} onClick={() => setOpen(product)}>
                                <Button size={Button.Sizes.Large} className={'w-full'}>
                                    Configure
                                </Button>
                            </div>
                        </div>
                    </ContentBox>
                ))}
            </div>
        </PageContentBlock>
    );
};
