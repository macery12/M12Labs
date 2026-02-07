import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useServerFromRoute } from '@/api/routes/admin/server';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import {
    faServer,
    faLayerGroup,
    faBalanceScale,
    faCashRegister,
} from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Spinner from '@/elements/Spinner';
import getNode from '@/api/routes/admin/nodes/getNode';
import { Node } from '@/api/routes/admin/nodes/getNodes';
import NodeStatus from '@admin/management/nodes/NodeStatus';
import { NavLink } from 'react-router-dom';

export default () => {
    const [node, setNode] = useState<Node | undefined>();
    const { data: server } = useServerFromRoute();
    const { billing } = useStoreState(state => state.everest.data!);

    useEffect(() => {
        if (server) {
            getNode(server.nodeId).then(node => setNode(node));
        }
    }, [server?.nodeId]);

    if (!server) return null;

    const product = server.relationships.product;
    const primaryAllocation = server.relationships.allocations[0];

    return (
        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6 mb-8`}>
            {/* Server Status */}
            <AdminBox icon={faServer} title={'Server Status'}>
                <div css={tw`space-y-4`}>
                    <div>
                        <Label>Status</Label>
                        <p css={tw`text-gray-300 capitalize`}>{server.status ?? 'Active'}</p>
                    </div>
                    <div>
                        <Label>Server ID</Label>
                        <p css={tw`text-gray-400 font-mono text-sm`}>{server.uuid}</p>
                    </div>
                    <div>
                        <Label>Owner</Label>
                        <p css={tw`text-gray-300`}>
                            {server.relationships.user?.username || 'Unknown'}
                        </p>
                    </div>
                </div>
            </AdminBox>

            {/* Node & Allocation Summary */}
            <AdminBox icon={faLayerGroup} title={'Node & Allocation'}>
                <div css={tw`space-y-4`}>
                    <div>
                        <Label>Node</Label>
                        {!node ? (
                            <Spinner size={'small'} />
                        ) : (
                            <NavLink to={`/admin/nodes/${node.id}`} css={tw`text-blue-400 hover:text-blue-300`}>
                                {node.name} &bull; {node.scheme}://{node.fqdn} <NodeStatus node={node.id} />
                            </NavLink>
                        )}
                    </div>
                    <div>
                        <Label>Primary Allocation</Label>
                        <p css={tw`text-gray-300 font-mono`}>
                            {primaryAllocation?.getDisplayText() || 'None'}
                        </p>
                    </div>
                    <div>
                        <Label>Total Allocations</Label>
                        <p css={tw`text-gray-300`}>
                            {server.relationships.allocations?.length || 0}
                            {server.featureLimits.allocations > 0 && ` / ${server.featureLimits.allocations}`}
                        </p>
                    </div>
                </div>
            </AdminBox>

            {/* Resource Limits Summary */}
            <AdminBox icon={faBalanceScale} title={'Resource Limits'}>
                <div css={tw`space-y-4`}>
                    <div>
                        <Label>CPU Limit</Label>
                        <p css={tw`text-gray-300`}>
                            {server.limits.cpu === 0 ? 'Unlimited' : `${server.limits.cpu}%`}
                        </p>
                    </div>
                    <div>
                        <Label>Memory Limit</Label>
                        <p css={tw`text-gray-300`}>
                            {server.limits.memory === 0 ? 'Unlimited' : `${server.limits.memory} MiB`}
                        </p>
                    </div>
                    <div>
                        <Label>Disk Limit</Label>
                        <p css={tw`text-gray-300`}>
                            {server.limits.disk === 0 ? 'Unlimited' : `${server.limits.disk} MiB`}
                        </p>
                    </div>
                </div>
            </AdminBox>

            {/* Billing Summary */}
            {billing.enabled && (
                <AdminBox icon={faCashRegister} title={'Billing Summary'}>
                    <div css={tw`space-y-4`}>
                        <div>
                            <Label>Billing Status</Label>
                            <p css={tw`text-gray-300`}>
                                {server.billingProductId ? 'Enabled' : 'Disabled'}
                            </p>
                        </div>
                        {server.billingProductId && product && (
                            <>
                                <div>
                                    <Label>Plan</Label>
                                    <p css={tw`text-gray-300`}>
                                        {product.name} - {billing.currency.symbol}
                                        {product.price} {billing.currency.code.toUpperCase()}
                                    </p>
                                </div>
                                {server.renewalDate && (
                                    <div>
                                        <Label>Next Renewal</Label>
                                        <p css={tw`text-gray-300`}>
                                            {new Date(server.renewalDate).toLocaleDateString()}
                                        </p>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </AdminBox>
            )}
        </div>
    );
};
