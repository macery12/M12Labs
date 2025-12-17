import { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import ContentBox from '@/elements/ContentBox';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { Button } from '@/elements/button';
import { Dialog } from '@/elements/dialog';
import useFlash from '@/plugins/useFlash';
import { changeEgg } from '@/api/routes/server/startup';
import { getProduct } from '@/api/routes/account/billing/products';
import { getEggInfo, type EggInfo } from '@/api/routes/account/billing/products';
import { Alert } from '@/elements/alert';
import SpinnerOverlay from '@/elements/SpinnerOverlay';

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const currentEggId = ServerContext.useStoreState(state => state.server.data!.eggId);
    const billingProductId = ServerContext.useStoreState(state => state.server.data!.billingProductId);
    const serverStatus = ServerContext.useStoreState(state => state.status.value);

    const [loading, setLoading] = useState(true);
    const [changing, setChanging] = useState(false);
    const [selectedEggId, setSelectedEggId] = useState<number>(currentEggId);
    const [availableEggs, setAvailableEggs] = useState<EggInfo[]>([]);
    const [currentEgg, setCurrentEgg] = useState<EggInfo | null>(null);
    const [allowEggChanges, setAllowEggChanges] = useState<boolean>(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const { clearFlashes, clearAndAddHttpError } = useFlash();

    useEffect(() => {
        if (!billingProductId) {
            setLoading(false);
            return;
        }

        // Validate currentEggId exists
        if (!currentEggId) {
            setLoading(false);
            return;
        }

        const fetchData = async () => {
            try {
                // Fetch current egg info first
                const currentEggInfo = await getEggInfo(currentEggId);
                setCurrentEgg(currentEggInfo);

                // Fetch product to get allowed eggs
                const product = await getProduct(billingProductId);
                const allowedEggs = product.allowedEggs || [product.eggId];
                
                // Fetch egg information for all allowed eggs
                const eggInfoPromises = allowedEggs.map(id => getEggInfo(id));
                const eggInfos = await Promise.all(eggInfoPromises);
                setAvailableEggs(eggInfos);
                
                // Check if category allows egg changes and has multiple eggs
                setAllowEggChanges(allowedEggs.length > 1);
                setLoading(false);
            } catch (error) {
                console.error(error);
                setLoading(false);
            }
        };

        fetchData();
    }, [billingProductId, currentEggId]);

    const handleChangeEgg = () => {
        if (selectedEggId === currentEggId) {
            return;
        }

        setChanging(true);
        clearFlashes('server:billing:egg');

        changeEgg(uuid, selectedEggId)
            .then(() => {
                window.location.reload(); // Reload to refresh server data
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'server:billing:egg', error });
                setChanging(false);
                setConfirmOpen(false);
            });
    };

    const selectedEgg = availableEggs.find(e => e.id === selectedEggId);
    const canChange = selectedEggId !== currentEggId && serverStatus === null;

    if (!allowEggChanges || availableEggs.length <= 1) {
        return null;
    }

    return (
        <>
            <ContentBox title={'Change Server Type'}>
                <SpinnerOverlay visible={loading} />
                <div className={'mb-4'}>
                    <Label>Current Server Type</Label>
                    <p className={'text-gray-400 text-sm'}>
                        {currentEgg?.name || 'Loading...'}
                    </p>
                    {currentEgg?.description && (
                        <p className={'text-gray-500 text-xs mt-1'}>{currentEgg.description}</p>
                    )}
                </div>

                <div className={'mb-4'}>
                    <Label>Select New Server Type</Label>
                    <Select value={selectedEggId} onChange={e => setSelectedEggId(Number(e.currentTarget.value))}>
                        {availableEggs.map(egg => (
                            <option key={egg.id} value={egg.id}>
                                {egg.name}
                            </option>
                        ))}
                    </Select>
                    {selectedEgg?.description && selectedEggId !== currentEggId && (
                        <p className={'text-gray-400 text-xs mt-2'}>{selectedEgg.description}</p>
                    )}
                </div>

                {serverStatus !== null && (
                    <Alert type={'warning'} className={'mb-4'}>
                        The server must be stopped before you can change the server type.
                    </Alert>
                )}

                {selectedEggId !== currentEggId && serverStatus === null && (
                    <Alert type={'danger'} className={'mb-4'}>
                        <strong>Warning:</strong> Changing the server type will trigger a complete reinstall. All server
                        data, including files, databases, and configurations, will be permanently deleted. This action
                        cannot be undone.
                    </Alert>
                )}

                <Button.Danger onClick={() => setConfirmOpen(true)} disabled={!canChange || changing}>
                    {changing ? 'Changing Server Type...' : 'Change Server Type'}
                </Button.Danger>
            </ContentBox>

            <Dialog.Confirm
                open={confirmOpen}
                title={'Confirm Server Type Change'}
                confirm={'Change Server Type'}
                onClose={() => setConfirmOpen(false)}
                onConfirmed={handleChangeEgg}
            >
                <p className={'text-sm mb-2'}>
                    You are about to change your server type from <strong>{currentEgg?.name}</strong> to{' '}
                    <strong>{selectedEgg?.name}</strong>.
                </p>
                <p className={'text-sm mb-2'}>
                    <strong className={'text-red-400'}>This will permanently delete all server data</strong>, including:
                </p>
                <ul className={'list-disc list-inside text-sm mb-2 text-gray-400'}>
                    <li>All files and directories</li>
                    <li>Database contents</li>
                    <li>Server configurations</li>
                    <li>Any custom modifications</li>
                </ul>
                <p className={'text-sm'}>
                    The server will be reinstalled with the new server type. Are you sure you want to continue?
                </p>
            </Dialog.Confirm>
        </>
    );
};
