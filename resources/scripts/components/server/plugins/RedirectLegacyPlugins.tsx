import { Navigate, useLocation } from 'react-router-dom';

const RedirectLegacyPlugins = () => {
    const location = useLocation();
    const isModpacks = location.pathname.includes('/modpacks');

    const match = location.pathname.match(/^(.*)\/(mods|modpacks)(?:\/.*)?$/);
    const basePath = match ? `${match[1]}/marketplace` : '/marketplace';

    if (isModpacks) {
        return <Navigate to={`${basePath}?type=modpacks&provider=curseforge`} replace />;
    }

    return <Navigate to={`${basePath}?type=mods&provider=modrinth`} replace />;
};

export default RedirectLegacyPlugins;
