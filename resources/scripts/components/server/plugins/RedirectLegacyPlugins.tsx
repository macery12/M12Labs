import { Navigate, useLocation } from 'react-router-dom';

const RedirectLegacyPlugins = () => {
    const location = useLocation();
    const isModpacks = location.pathname.includes('/modpacks');

    const match = location.pathname.match(/^(.*)\/(mods|modpacks)(?:\/.*)?$/);
    const basePath = match ? `${match[1]}/plugins` : '/plugins';

    if (isModpacks) {
        return <Navigate to={`${basePath}?provider=curseforge&resource=modpacks`} replace />;
    }

    return <Navigate to={`${basePath}?provider=modrinth&resource=mods`} replace />;
};

export default RedirectLegacyPlugins;
