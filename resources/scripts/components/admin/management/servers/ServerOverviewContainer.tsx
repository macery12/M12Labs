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

const ResourceLimitBar = ({ label, value, max, unit }: { label: string; value: number; max: number; unit: string }) => {
    const percentage = max === 0 ? 0 : Math.min((value / max) * 100, 100);
    const isUnlimited = max === 0;

    return (
        <div>
            <div css={tw`flex justify-between items-center mb-1`}>
                <span css={tw`text-xs font-medium text-gray-400`}>{label}</span>
                <span css={tw`text-xs text-gray-300`}>{isUnlimited ? 'Unlimited' : `${value} / ${max} ${unit}`}</span>
            </div>
            <div css={tw`w-full bg-gray-700 rounded-full h-2 overflow-hidden`}>
                <div
                    css={tw`h-full rounded-full transition-all duration-300`}
                    style={{
                        width: isUnlimited ? '100%' : `${percentage}%`,
                        backgroundColor: isUnlimited
                            ? '#6b7280'
                            : percentage > 80
                            ? '#ef4444'
                            : percentage > 60
                            ? '#f59e0b'
                            : '#10b981',
                    }}
                />
            </div>
        </div>
    );
};

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
        <div css={tw`grid grid-cols-1 lg:grid-cols-3 gap-4`}>
            {/* Left Column - Server Status and Node & Network */}
            <div css={tw`lg:col-span-1 space-y-4`}>
                {/* Server Status */}
                <AdminBox icon={faServer} title={'Server Status'}>
                    <dl css={tw`space-y-3`}>
                        <div>
                            <dt css={tw`text-sm font-medium text-gray-400`}>Status</dt>
                            <dd css={tw`mt-1 text-sm text-gray-300 capitalize`}>{server.status ?? 'Active'}</dd>
                        </div>
                        <div>
                            <dt css={tw`text-sm font-medium text-gray-400`}>Owner</dt>
                            <dd css={tw`mt-1 text-sm text-gray-300`}>
                                {server.relationships.user?.username || 'Unknown'}
                            </dd>
                        </div>
                        <div>
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
                    <dl css={tw`space-y-3`}>
                        <div>
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
                        <div>
                            <dt css={tw`text-sm font-medium text-gray-400`}>Primary Allocation</dt>
                            <dd css={tw`mt-1 text-sm text-gray-300 font-mono`}>
                                {primaryAllocation?.getDisplayText() || 'None'}
                            </dd>
                        </div>
                        <div>
                            <dt css={tw`text-sm font-medium text-gray-400`}>Total Allocations</dt>
                            <dd css={tw`mt-1 text-sm text-gray-300`}>
                                {server.relationships.allocations?.length || 0}
                                {server.featureLimits.allocations > 0 && ` / ${server.featureLimits.allocations}`}
                            </dd>
                        </div>
                    </dl>
                </AdminBox>
            </div>

            {/* Middle Column - Resource Limits (Visual) */}
            <div css={tw`lg:col-span-1`}>
                <AdminBox icon={faBalanceScale} title={'Resource Limits'}>
                    <div css={tw`space-y-4`}>
                        <ResourceLimitBar
                            label="CPU Usage"
                            value={server.limits.cpu}
                            max={server.limits.cpu}
                            unit="%"
                        />
                        <ResourceLimitBar
                            label="Memory"
                            value={server.limits.memory}
                            max={server.limits.memory}
                            unit="MiB"
                        />
                        <ResourceLimitBar
                            label="Disk Space"
                            value={server.limits.disk}
                            max={server.limits.disk}
                            unit="MiB"
                        />
                        <div css={tw`pt-3 border-t border-gray-700`}>
                            <div css={tw`grid grid-cols-3 gap-2 text-center`}>
                                <div>
                                    <div css={tw`text-2xl font-bold text-blue-400`}>
                                        {server.limits.cpu === 0 ? '∞' : `${server.limits.cpu}%`}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>CPU</div>
                                </div>
                                <div>
                                    <div css={tw`text-2xl font-bold text-green-400`}>
                                        {server.limits.memory === 0 ? '∞' : `${server.limits.memory}`}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>RAM (MiB)</div>
                                </div>
                                <div>
                                    <div css={tw`text-2xl font-bold text-purple-400`}>
                                        {server.limits.disk === 0 ? '∞' : `${server.limits.disk}`}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>Disk (MiB)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </AdminBox>
            </div>

            {/* Right Column - Billing Summary */}
            {billing.enabled && (
                <div css={tw`lg:col-span-1`}>
                    <AdminBox icon={faCashRegister} title={'Billing Summary'}>
                        <dl css={tw`space-y-3`}>
                            <div>
                                <dt css={tw`text-sm font-medium text-gray-400`}>Billing Status</dt>
                                <dd css={tw`mt-1 text-sm text-gray-300`}>
                                    {server.billingProductId ? (
                                        <span css={tw`text-green-400 font-medium`}>✓ Enabled</span>
                                    ) : (
                                        <span css={tw`text-gray-400`}>Disabled</span>
                                    )}
                                </dd>
                            </div>
                            {server.billingProductId && product && (
                                <>
                                    <div>
                                        <dt css={tw`text-sm font-medium text-gray-400`}>Billing Cycle</dt>
                                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                                            {server.billingDays ? `${server.billingDays} days` : '30 days'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt css={tw`text-sm font-medium text-gray-400`}>Plan</dt>
                                        <dd css={tw`mt-1 text-sm text-gray-300`}>
                                            {product.name} - {billing.currency.symbol}
                                            {product.price} {billing.currency.code.toUpperCase()}
                                        </dd>
                                    </div>
                                    {server.renewalDate && (
                                        <div>
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
                </div>
            )}
        </div>
    );
};
