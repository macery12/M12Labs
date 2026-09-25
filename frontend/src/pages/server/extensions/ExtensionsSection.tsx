import { Routes, Route, Navigate } from 'react-router-dom';
import { useServer } from '@/components/server/ServerContext';
import ExtensionsGallery from './ExtensionsGallery';

// Mounted at the server `extensions/*` splat, which is now only the gallery.
// The pages themselves are mounted as siblings at
// extensions/ext/<id>/<slug>/* (see extensionServerRoutes in ./registry), so
// each appears in the sidebar category it declared rather than behind this tab.
// React Router ranks those static segments above this splat, so they win.
export default function ExtensionsSection() {
    const server = useServer();

    return (
        <Routes>
            <Route index element={<ExtensionsGallery />} />
            {/* An extension that was uninstalled or disabled leaves stale links behind. */}
            <Route path="*" element={<Navigate to={`/server/${server.id}/extensions`} replace />} />
        </Routes>
    );
}
