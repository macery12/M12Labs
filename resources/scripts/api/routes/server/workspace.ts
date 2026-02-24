import useSWR from 'swr';
import http from '@/api/http';
import { useServerSWRKey } from '@/plugins/useSWRKey';
import { ServerContext } from '@/state/server';
import type { ConsoleWorkspaceLayout } from '@definitions/server';

const useConsoleWorkspaceLayout = () => {
    const uuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const key = useServerSWRKey(['workspace', 'console']);

    return useSWR<ConsoleWorkspaceLayout>(uuid ? key : null, async () => {
        const { data } = await http.get(`/api/client/servers/${uuid}/workspace/console-layout`);

        return data as ConsoleWorkspaceLayout;
    });
};

const saveConsoleWorkspaceLayout = async (uuid: string, payload: ConsoleWorkspaceLayout): Promise<ConsoleWorkspaceLayout> => {
    const { data } = await http.put(`/api/client/servers/${uuid}/workspace/console-layout`, payload);

    return data as ConsoleWorkspaceLayout;
};

const resetConsoleWorkspaceLayout = async (uuid: string): Promise<ConsoleWorkspaceLayout> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/workspace/console-layout/reset`);

    return data as ConsoleWorkspaceLayout;
};

export { useConsoleWorkspaceLayout, saveConsoleWorkspaceLayout, resetConsoleWorkspaceLayout };
