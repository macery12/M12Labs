import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useServerFromRoute } from '@/api/routes/admin/server';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faServer, faLayerGroup, faBalanceScale, faCashRegister, faCopy } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Spinner from '@/elements/Spinner';
import getNode from '@/api/routes/admin/nodes/getNode';
import { Node } from '@/api/routes/admin/nodes/getNodes';
import NodeStatus from '@admin/management/nodes/NodeStatus';
import { NavLink } from 'react-router-dom';
import { useFlashKey } from '@/plugins/useFlash';

// Status badge colors
const STATUS_COLORS = {
    active: { backgroundColor: '#10b98133', color: '#10b981' },
    running: { backgroundColor: '#10b98133', color: '#10b981' },
    default: { backgroundColor: '#ef444433', color: '#ef4444' },
};

const getStatusColors = (status: string | null) => {
    return STATUS_COLORS[status as keyof typeof STATUS_COLORS] || STATUS_COLORS.default;
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
        <div css={tw`space-y-4`}>
            {/* Row 1: Three equal-width cards */}
            <div css={tw`grid grid-cols-1 md:grid-cols-3 gap-4`}>
                {/* Server Status Card */}
                <div css={tw`md:col-span-1`}>
                    <AdminBox icon={faServer} title={'Server Status'}>
                        <div css={tw`space-y-4`}>
                            {/* Primary: Server Status - Visually prominent */}
                            <div css={tw`flex items-center gap-2`}>
                                <span
                                    css={tw`px-3 py-1 rounded-full text-sm font-semibold capitalize`}
                                    style={getStatusColors(server.status)}
                                >
                                    {server.status ?? 'Active'}
                                </span>
                            </div>

                            {/* Secondary: Owner and Server ID */}
                            <div css={tw`space-y-2 pt-2 border-t border-gray-700`}>
                                <div css={tw`flex justify-between items-center`}>
                                    <span css={tw`text-xs text-gray-500`}>Owner</span>
                                    <span css={tw`text-sm text-gray-300`}>
                                        {server.relationships.user?.username || 'Unknown'}
                                    </span>
                                </div>
                                <div css={tw`flex justify-between items-center gap-2`}>
                                    <span css={tw`text-xs text-gray-500`}>Server ID</span>
                                    <div
                                        css={tw`flex items-center gap-1 cursor-pointer hover:text-blue-400 transition-colors`}
                                        onClick={() => copyToClipboard(server.uuid)}
                                        title="Click to copy"
                                    >
                                        <span css={tw`text-xs text-gray-400 font-mono truncate max-w-[140px]`}>
                                            {server.uuid}
                                        </span>
                                        <FontAwesomeIcon icon={faCopy} css={tw`text-xs text-gray-500 flex-shrink-0`} />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </AdminBox>
                </div>

                {/* Resource Limits Card */}
                <div css={tw`md:col-span-1`}>
                    <AdminBox icon={faBalanceScale} title={'Resource Limits'}>
                        <div css={tw`space-y-4`}>
                            {/* Primary: Large numbers for main resources */}
                            <div css={tw`grid grid-cols-3 gap-2`}>
                                <div css={tw`text-center`}>
                                    <div css={tw`text-2xl font-bold text-blue-400`}>
                                        {server.limits.cpu === 0 ? '∞' : server.limits.cpu}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>CPU</div>
                                    <div css={tw`text-xs text-gray-500`}>
                                        {server.limits.cpu === 0 ? 'Unlimited' : '%'}
                                    </div>
                                </div>
                                <div css={tw`text-center`}>
                                    <div css={tw`text-2xl font-bold text-green-400`}>
                                        {server.limits.memory === 0 ? '∞' : server.limits.memory}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>Memory</div>
                                    <div css={tw`text-xs text-gray-500`}>
                                        {server.limits.memory === 0 ? 'Unlimited' : 'MiB'}
                                    </div>
                                </div>
                                <div css={tw`text-center`}>
                                    <div css={tw`text-2xl font-bold text-purple-400`}>
                                        {server.limits.disk === 0 ? '∞' : server.limits.disk}
                                    </div>
                                    <div css={tw`text-xs text-gray-400 mt-1`}>Disk</div>
                                    <div css={tw`text-xs text-gray-500`}>
                                        {server.limits.disk === 0 ? 'Unlimited' : 'MiB'}
                                    </div>
                                </div>
                            </div>

                            {/* Tertiary: Subtle footer for secondary info */}
                            <div css={tw`pt-2 border-t border-gray-700 grid grid-cols-2 gap-2 text-xs`}>
                                <div css={tw`flex justify-between`}>
                                    <span css={tw`text-gray-500`}>Swap</span>
                                    <span css={tw`text-gray-400`}>
                                        {server.limits.swap === 0 ? '∞' : `${server.limits.swap}`}
                                    </span>
                                </div>
                                <div css={tw`flex justify-between`}>
                                    <span css={tw`text-gray-500`}>I/O</span>
                                    <span css={tw`text-gray-400`}>
                                        {server.limits.io === 0 ? '∞' : server.limits.io}
                                    </span>
                                </div>
                                <div css={tw`flex justify-between`} title="Out of Memory Killer">
                                    <span css={tw`text-gray-500`}>OOM Killer</span>
                                    <span css={tw`text-gray-400`}>
                                        {server.limits.oomKiller ? 'Enabled' : 'Disabled'}
                                    </span>
                                </div>
                                {server.limits.threads && (
                                    <div css={tw`flex justify-between`}>
                                        <span css={tw`text-gray-500`}>Threads</span>
                                        <span css={tw`text-gray-400`}>{server.limits.threads}</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </AdminBox>
                </div>

                {/* Billing Summary Card */}
                <div css={tw`md:col-span-1`}>
                    <AdminBox icon={faCashRegister} title={'Billing Summary'}>
                        <div css={tw`space-y-4`}>
                            {billing.enabled && server.billingProductId && product ? (
                                <>
                                    {/* Primary: Plan and Cost */}
                                    <div>
                                        <div css={tw`text-lg font-semibold text-gray-200`}>{product.name}</div>
                                        <div css={tw`text-2xl font-bold text-green-400 mt-1`}>
                                            {billing.currency.symbol}
                                            {product.price}
                                            <span css={tw`text-sm text-gray-400 ml-1`}>
                                                {billing.currency.code.toUpperCase()}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Secondary: Billing details */}
                                    <div css={tw`space-y-2 pt-2 border-t border-gray-700`}>
                                        <div css={tw`flex justify-between items-center`}>
                                            <span css={tw`text-xs text-gray-500`}>Billing Cycle</span>
                                            <span css={tw`text-sm text-gray-300`}>
                                                {server.billingDays ? `${server.billingDays} days` : '30 days'}
                                            </span>
                                        </div>
                                        {server.renewalDate && (
                                            <div css={tw`flex justify-between items-center`}>
                                                <span css={tw`text-xs text-gray-500`}>Next Renewal</span>
                                                <span css={tw`text-sm font-medium text-blue-400`}>
                                                    {new Date(server.renewalDate).toLocaleDateString()}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <div css={tw`flex items-center justify-center py-8`}>
                                    <span css={tw`text-gray-400`}>Billing Disabled</span>
                                </div>
                            )}
                        </div>
                    </AdminBox>
                </div>
            </div>

            {/* Row 2: Node & Network - Full width card */}
            <div css={tw`grid grid-cols-1`}>
                <AdminBox icon={faLayerGroup} title={'Node & Network'}>
                    <div css={tw`grid grid-cols-1 md:grid-cols-3 gap-4`}>
                        {/* Primary: Node name with health indicator */}
                        <div css={tw`md:col-span-2`}>
                            <div css={tw`text-sm text-gray-500 mb-2`}>Node</div>
                            {!node ? (
                                <Spinner size={'small'} />
                            ) : (
                                <NavLink
                                    to={`/admin/nodes/${node.id}`}
                                    css={tw`flex items-center gap-2 text-blue-400 hover:text-blue-300 transition-colors`}
                                >
                                    <span css={tw`font-medium text-base`}>{node.name}</span>
                                    <span css={tw`text-gray-500`}>&bull;</span>
                                    <span css={tw`text-sm text-gray-400`}>
                                        {node.scheme}://{node.fqdn}
                                    </span>
                                    <NodeStatus node={node.id} />
                                </NavLink>
                            )}
                        </div>

                        {/* Secondary: Allocation count */}
                        <div css={tw`md:col-span-1`}>
                            <div css={tw`text-sm text-gray-500 mb-2`}>Total Allocations</div>
                            <div css={tw`text-base text-gray-300`}>
                                {server.relationships.allocations?.length || 0}
                                {server.featureLimits.allocations > 0 && (
                                    <span css={tw`text-gray-500`}> / {server.featureLimits.allocations}</span>
                                )}
                            </div>
                        </div>

                        {/* Primary allocation with copy action */}
                        <div css={tw`md:col-span-3 pt-3 border-t border-gray-700`}>
                            <div css={tw`text-sm text-gray-500 mb-2`}>Primary Allocation</div>
                            <div
                                css={tw`inline-flex items-center gap-2 cursor-pointer hover:text-blue-400 transition-colors`}
                                onClick={() => primaryAllocation && copyToClipboard(primaryAllocation.getDisplayText())}
                                title="Click to copy"
                            >
                                <span css={tw`font-mono text-base text-gray-300`}>
                                    {primaryAllocation?.getDisplayText() || 'None'}
                                </span>
                                {primaryAllocation && <FontAwesomeIcon icon={faCopy} css={tw`text-sm text-gray-500`} />}
                            </div>
                        </div>
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
