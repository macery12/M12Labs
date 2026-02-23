import { Navigate, useLocation } from 'react-router-dom';

const RedirectLegacyPlugins = () => {
    const location = useLocation();
    const isModpacks = location.pathname.includes('/modpacks');

    if (isModpacks) {
        return <Navigate to="/plugins?provider=curseforge&resource=modpacks" replace />;
    }

    return <Navigate to="/plugins?provider=modrinth&resource=mods" replace />;
};

export default RedirectLegacyPlugins;
