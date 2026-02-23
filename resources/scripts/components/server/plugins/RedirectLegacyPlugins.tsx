import { Navigate, useLocation } from 'react-router-dom';

const RedirectLegacyPlugins = () => {
    const location = useLocation();
    const isModpacks = location.pathname.includes('/modpacks');

    const basePath = location.pathname
        .replace(/\/mods.*/i, '/plugins')
        .replace(/\/modpacks.*/i, '/plugins');

    if (isModpacks) {
        return <Navigate to={`${basePath}?provider=curseforge&resource=modpacks`} replace />;
    }

    return <Navigate to={`${basePath}?provider=modrinth&resource=mods`} replace />;
};

export default RedirectLegacyPlugins;
