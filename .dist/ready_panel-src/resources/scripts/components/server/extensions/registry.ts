import React from 'react';

export interface ExtensionRouteDefinition {
    id: string;
    route: string;
    component: React.LazyExoticComponent<() => JSX.Element>;
}

export const extensionRoutes: ExtensionRouteDefinition[] = [
    {
        id: 'minecraft_player_manager',
        route: 'minecraft_player_manager',
        component: React.lazy(() => import('./PlayerManagerContainer')),
    },
    {
        id: 'discordsrv_helper',
        route: 'discordsrv_helper',
        component: React.lazy(() => import('./discordsrv_helper/DiscordSrvHelperContainer')),
    },
];

