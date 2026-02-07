import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useServerFromRoute } from '@/api/routes/admin/server';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faServer, faLayerGroup, faBalanceScale, faCashRegister } from '@fortawesome/free-solid-svg-icons';
import Spinner from '@/elements/Spinner';
import getNode from '@/api/routes/admin/nodes/getNode';
import { Node } from '@/api/routes/admin/nodes/getNodes';
import NodeStatus from '@admin/management/nodes/NodeStatus';
import { NavLink } from 'react-router-dom';
import { useFlashKey } from '@/plugins/useFlash';

export default () => {
    const [node, setNode] = useState<Node | undefined>();
    const { data: server } = useServerFromRoute();
    const { billing } = useStoreState(state => state.everest.data!);
    const { addFlash } = useFlashKey('server');

    useEffect(() => {
        if (server) {
            getNode(server.nodeId).then(node => setNode(node));
        }
    }, [server?.nodeId]);

    if (!server) return null;

    const product = server.relationships.product;
    const primaryAllocation = server.relationships.allocations[0];

    const copyToClipboard = (text: string) => {
        navigator.clipboard
            .writeText(text)
            .then(() => {
                addFlash({ type: 'success', message: 'UUID copied to clipboard!' });
            })
            .catch(() => {
                addFlash({ type: 'error', message: 'Failed to copy UUID to clipboard' });
            });
    };

    return (
        <div css={tw`space-y-4`}>
            {/* Server Status */}
            <AdminBox icon={faServer} title={'Server Status'}>
                <dl css={tw`grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2`}>
                    <div css={tw`sm:col-span-1`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Status</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300 capitalize`}>{server.status ?? 'Active'}</dd>
                    </div>
                    <div css={tw`sm:col-span-1`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Owner</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300`}>{server.relationships.user?.username || 'Unknown'}</dd>
                    </div>
                    <div css={tw`sm:col-span-2`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Server ID</dt>
                        <dd
                            css={tw`mt-1 flex items-center gap-2 cursor-pointer hover:text-blue-400 transition-colors`}
                            onClick={() => copyToClipboard(server.uuid)}
                            title="Click to copy"
                        >
                            <span css={tw`text-sm text-gray-400 font-mono`}>{server.uuid}</span>
                            <svg
                                css={tw`w-4 h-4 text-gray-500 hover:text-blue-400 flex-shrink-0`}
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                                />
                            </svg>
                        </dd>
                    </div>
                </dl>
            </AdminBox>

            {/* Node & Network */}
            <AdminBox icon={faLayerGroup} title={'Node & Network'}>
                <dl css={tw`grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2`}>
                    <div css={tw`sm:col-span-2`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Node</dt>
                        <dd css={tw`mt-1 text-sm`}>
                            {!node ? (
                                <Spinner size={'small'} />
                            ) : (
                                <NavLink to={`/admin/nodes/${node.id}`} css={tw`text-blue-400 hover:text-blue-300`}>
                                    {node.name} &bull; {node.scheme}://{node.fqdn} <NodeStatus node={node.id} />
                                </NavLink>
                            )}
                        </dd>
                    </div>
                    <div css={tw`sm:col-span-1`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Primary Allocation</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300 font-mono`}>
                            {primaryAllocation?.getDisplayText() || 'None'}
                        </dd>
                    </div>
                    <div css={tw`sm:col-span-1`}>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Total Allocations</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                            {server.relationships.allocations?.length || 0}
                            {server.featureLimits.allocations > 0 && ` / ${server.featureLimits.allocations}`}
                        </dd>
                    </div>
                </dl>
            </AdminBox>

            {/* Resource Limits */}
            <AdminBox icon={faBalanceScale} title={'Resource Limits'}>
                <dl css={tw`grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-3`}>
                    <div>
                        <dt css={tw`text-sm font-medium text-gray-400`}>CPU</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                            {server.limits.cpu === 0 ? 'Unlimited' : `${server.limits.cpu}%`}
                        </dd>
                    </div>
                    <div>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Memory</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                            {server.limits.memory === 0 ? 'Unlimited' : `${server.limits.memory} MiB`}
                        </dd>
                    </div>
                    <div>
                        <dt css={tw`text-sm font-medium text-gray-400`}>Disk</dt>
                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                            {server.limits.disk === 0 ? 'Unlimited' : `${server.limits.disk} MiB`}
                        </dd>
                    </div>
                </dl>
            </AdminBox>

            {/* Billing Summary */}
            {billing.enabled && (
                <AdminBox icon={faCashRegister} title={'Billing Summary'}>
                    <dl css={tw`grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2`}>
                        <div css={tw`sm:col-span-1`}>
                            <dt css={tw`text-sm font-medium text-gray-400`}>Billing Status</dt>
                            <dd css={tw`mt-1 text-sm text-gray-300`}>
                                {server.billingProductId ? 'Enabled' : 'Disabled'}
                            </dd>
                        </div>
                        {server.billingProductId && product && (
                            <>
                                <div css={tw`sm:col-span-1`}>
                                    <dt css={tw`text-sm font-medium text-gray-400`}>Billing Cycle</dt>
                                    <dd css={tw`mt-1 text-sm text-gray-300`}>
                                        {server.billingDays ? `${server.billingDays} days` : '30 days'}
                                    </dd>
                                </div>
                                <div css={tw`sm:col-span-2`}>
                                    <dt css={tw`text-sm font-medium text-gray-400`}>Plan</dt>
                                    <dd css={tw`mt-1 text-sm text-gray-300`}>
                                        {product.name} - {billing.currency.symbol}
                                        {product.price} {billing.currency.code.toUpperCase()}
                                    </dd>
                                </div>
                                {server.renewalDate && (
                                    <div css={tw`sm:col-span-2`}>
                                        <dt css={tw`text-sm font-medium text-gray-400`}>Next Renewal</dt>
                                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                                            {new Date(server.renewalDate).toLocaleDateString()}
                                        </dd>
                                    </div>
                                )}
                            </>
                        )}
                    </dl>
                </AdminBox>
            )}
        </div>
    );
};
