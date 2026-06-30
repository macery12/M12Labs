import { Navigate, useParams } from 'react-router-dom';

// The old admin Nodes/Servers areas were merged into one Infrastructure section.
// These keep deep links alive: `/v2/admin/nodes/:id` → `/v2/admin/infrastructure/nodes/:id`,
// and the bare overviews → the merged overview. Mounted on hidden `nodes/*` /
// `servers/*` registry entries so they redirect without showing in the sidebar.
function redirect(prefix: 'nodes' | 'servers') {
    return function InfraRedirect() {
        const rest = useParams()['*'] ?? '';
        const target = rest
            ? `/v2/admin/infrastructure/${prefix}/${rest}`
            : '/v2/admin/infrastructure';
        return <Navigate to={target} replace />;
    };
}

export const NodesRedirect = redirect('nodes');
export const ServersRedirect = redirect('servers');
