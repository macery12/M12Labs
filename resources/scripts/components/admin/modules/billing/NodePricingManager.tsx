import { useState, useEffect } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { faMapMarkerAlt, faUndo, faRedo, faSave } from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import useFlash from '@/plugins/useFlash';
import { Alert } from '@/elements/alert';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import tw from 'twin.macro';
import { 
    getNodePricing, 
    updateNodePricing, 
    batchUpdateNodePricing, 
    resetNodePricing, 
    resetAllNodePricing,
    NodePricing 
} from '@/api/routes/admin/billing/nodePricing';

const formatMultiplierDisplay = (multiplier: number): string => {
    if (multiplier === 1.0) return 'Standard (1.00x)';
    if (multiplier > 1.0) {
        const percentage = Math.round((multiplier - 1) * 100);
        return `+${percentage}% (${multiplier.toFixed(2)}x)`;
    }
    const percentage = Math.round((1 - multiplier) * 100);
    return `-${percentage}% (${multiplier.toFixed(2)}x)`;
};

export default () => {
    const { clearFlashes, addFlash } = useFlash();
    const [nodes, setNodes] = useState<NodePricing[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [localMultipliers, setLocalMultipliers] = useState<Record<number, number>>({});

    useEffect(() => {
        loadNodePricing();
    }, []);

    const loadNodePricing = async () => {
        try {
            setLoading(true);
            const data = await getNodePricing();
            setNodes(data);
            
            // Initialize local multipliers
            const multipliers: Record<number, number> = {};
            data.forEach(node => {
                multipliers[node.id] = node.price_multiplier;
            });
            setLocalMultipliers(multipliers);
        } catch (error) {
            console.error('Failed to load node pricing:', error);
            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'error',
                message: 'Failed to load node pricing data.',
            });
        } finally {
            setLoading(false);
        }
    };

    const handleMultiplierChange = (nodeId: number, value: string) => {
        // Allow empty string for typing
        if (value === '') {
            setLocalMultipliers(prev => ({
                ...prev,
                [nodeId]: 0,
            }));
            return;
        }
        
        // Parse and validate the value
        const numValue = parseFloat(value);
        
        // Only update if it's a valid number
        if (!isNaN(numValue)) {
            // Clamp between 0 and 5
            const clampedValue = Math.max(0, Math.min(5, numValue));
            setLocalMultipliers(prev => ({
                ...prev,
                [nodeId]: clampedValue,
            }));
        }
    };

    const handleSaveAll = async () => {
        clearFlashes('admin:billing:node-pricing');
        setSaving(true);

        try {
            // Build array of updates
            const updates = nodes
                .filter(node => localMultipliers[node.id] !== node.price_multiplier)
                .map(node => ({
                    id: node.id,
                    price_multiplier: localMultipliers[node.id],
                }));

            if (updates.length === 0) {
                addFlash({
                    key: 'admin:billing:node-pricing',
                    type: 'info',
                    message: 'No changes to save.',
                });
                setSaving(false);
                return;
            }

            await batchUpdateNodePricing(updates);
            
            // Reload data to get updated values
            await loadNodePricing();

            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'success',
                message: `Successfully updated ${updates.length} node pricing multiplier(s).`,
            });
        } catch (error) {
            console.error('Failed to save node pricing:', error);
            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'error',
                message: 'Failed to save node pricing. Please try again.',
            });
        } finally {
            setSaving(false);
        }
    };

    const handleReset = async (nodeId: number) => {
        clearFlashes('admin:billing:node-pricing');
        setSaving(true);

        try {
            await resetNodePricing(nodeId);
            await loadNodePricing();

            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'success',
                message: 'Successfully reset node pricing to 1.00x.',
            });
        } catch (error) {
            console.error('Failed to reset node pricing:', error);
            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'error',
                message: 'Failed to reset node pricing. Please try again.',
            });
        } finally {
            setSaving(false);
        }
    };

    const handleResetAll = async () => {
        clearFlashes('admin:billing:node-pricing');
        
        if (!confirm('Are you sure you want to reset all node pricing multipliers to 1.00x?')) {
            return;
        }

        setSaving(true);

        try {
            await resetAllNodePricing();
            await loadNodePricing();

            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'success',
                message: 'Successfully reset all node pricing multipliers to 1.00x.',
            });
        } catch (error) {
            console.error('Failed to reset all node pricing:', error);
            addFlash({
                key: 'admin:billing:node-pricing',
                type: 'error',
                message: 'Failed to reset all node pricing. Please try again.',
            });
        } finally {
            setSaving(false);
        }
    };

    const hasChanges = nodes.some(node => localMultipliers[node.id] !== node.price_multiplier);

    if (loading) {
        return (
            <AdminBox title={'Node Pricing Multipliers'} icon={faMapMarkerAlt}>
                <p className={'text-gray-400'}>Loading...</p>
            </AdminBox>
        );
    }

    if (nodes.length === 0) {
        return (
            <AdminBox title={'Node Pricing Multipliers'} icon={faMapMarkerAlt}>
                <Alert type={'info'}>
                    No nodes found. Create nodes first to configure location-based pricing.
                </Alert>
            </AdminBox>
        );
    }

    return (
        <AdminBox title={'Node Pricing Multipliers'} icon={faMapMarkerAlt}>
            <p className={'mb-4 text-gray-400'}>
                Configure location-based pricing for each node. The final checkout price will be multiplied by the selected node's multiplier.
            </p>

            <div css={tw`overflow-x-auto mb-4`}>
                <table css={tw`w-full border-collapse`}>
                    <thead>
                        <tr css={tw`border-b-2 border-neutral-600`}>
                            <th css={tw`text-left py-3 px-4 text-neutral-300 font-semibold`}>
                                Node
                            </th>
                            <th css={tw`text-left py-3 px-4 text-neutral-300 font-semibold`}>
                                Pricing Multiplier
                            </th>
                            <th css={tw`text-left py-3 px-4 text-neutral-300 font-semibold`}>
                                Effect on Price
                            </th>
                            <th css={tw`text-right py-3 px-4 text-neutral-300 font-semibold`}>
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {nodes.map((node) => {
                            const currentMultiplier = localMultipliers[node.id] ?? 1.0;
                            const hasChanged = currentMultiplier !== node.price_multiplier;
                            
                            return (
                                <tr 
                                    key={node.id} 
                                    css={[
                                        tw`border-b border-neutral-700 hover:bg-neutral-700 transition-colors`,
                                        hasChanged && tw`bg-neutral-800`,
                                    ]}
                                >
                                    <td css={tw`py-3 px-4`}>
                                        <div css={tw`flex flex-col`}>
                                            <span css={tw`text-neutral-100 font-medium`}>{node.name}</span>
                                            <span css={tw`text-xs text-neutral-400`}>
                                                ID: {node.id}
                                                {node.deployable && <span css={tw`ml-2 text-green-400`}>• Paid</span>}
                                                {node.deployable_free && <span css={tw`ml-2 text-blue-400`}>• Free</span>}
                                            </span>
                                        </div>
                                    </td>
                                    <td css={tw`py-3 px-4`}>
                                        <div css={tw`flex items-center gap-2`}>
                                            <Input
                                                type={'text'}
                                                value={currentMultiplier}
                                                onChange={e => handleMultiplierChange(node.id, e.target.value)}
                                                onBlur={e => {
                                                    // On blur, ensure we have a valid number
                                                    const val = parseFloat(e.target.value);
                                                    if (isNaN(val) || val < 0) {
                                                        handleMultiplierChange(node.id, '1.0');
                                                    }
                                                }}
                                                disabled={saving}
                                                css={tw`w-24`}
                                                placeholder="1.00"
                                            />
                                            <span css={tw`text-xs text-neutral-400`}>x</span>
                                        </div>
                                    </td>
                                    <td css={tw`py-3 px-4`}>
                                        <span 
                                            css={[
                                                tw`font-medium`,
                                                currentMultiplier === 1.0 && tw`text-blue-400`,
                                                currentMultiplier > 1.0 && tw`text-red-400`,
                                                currentMultiplier < 1.0 && tw`text-green-400`,
                                            ]}
                                        >
                                            {formatMultiplierDisplay(currentMultiplier)}
                                        </span>
                                    </td>
                                    <td css={tw`py-3 px-4 text-right`}>
                                        <Button
                                            type="button"
                                            onClick={() => handleReset(node.id)}
                                            className="!bg-neutral-600 hover:!bg-neutral-500"
                                            disabled={saving || currentMultiplier === 1.0}
                                            size="small"
                                        >
                                            <FontAwesomeIcon icon={faUndo} className="mr-2" />
                                            Reset to 1.00x
                                        </Button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <Alert type={'info'} className={'mt-4'}>
                <strong>How it works:</strong> When a customer selects a node during checkout, the final price is calculated as: 
                <code css={tw`mx-1 px-1 bg-neutral-700 rounded`}>base_price × billing_cycle_multiplier × node_multiplier</code>. 
                For example, a $10/month product with 1.15x node multiplier would cost $11.50/month at that location.
            </Alert>

            <div className={'mt-6 flex justify-between'}>
                <Button
                    type="button"
                    onClick={handleResetAll}
                    className="!bg-red-500 hover:!bg-red-600"
                    disabled={saving}
                >
                    <FontAwesomeIcon icon={faRedo} className="mr-2" />
                    Reset All to 1.00x
                </Button>

                <div css={tw`flex gap-2`}>
                    {hasChanges && (
                        <span css={tw`text-yellow-400 self-center mr-2 text-sm`}>
                            You have unsaved changes
                        </span>
                    )}
                    <Button onClick={handleSaveAll} disabled={saving || !hasChanges}>
                        <FontAwesomeIcon icon={faSave} className="mr-2" />
                        {saving ? 'Saving...' : 'Save All Changes'}
                    </Button>
                </div>
            </div>
        </AdminBox>
    );
};
