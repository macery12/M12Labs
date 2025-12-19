import http, { FractalResponseList } from '@/api/http';
import { EggVariable, Transformers } from '@definitions/server';
import { AxiosError } from 'axios';
import useSWR, { SWRConfiguration } from 'swr';

const getServerStartup = (
    uuid: string,
    fallbackData?: { invocation: string; variables: EggVariable[]; dockerImages: Record<string, string> },
    config?: SWRConfiguration<
        { invocation: string; variables: EggVariable[]; dockerImages: Record<string, string> },
        AxiosError
    >,
) =>
    useSWR(
        [uuid, '/startup'],
        async (): Promise<{ invocation: string; variables: EggVariable[]; dockerImages: Record<string, string> }> => {
            const { data } = await http.get(`/api/client/servers/${uuid}/startup`);

            const variables = ((data as FractalResponseList).data || []).map(Transformers.toEggVariable);

            return {
                variables,
                invocation: data.meta.startup_command,
                dockerImages: data.meta.docker_images || {},
            };
        },
        { fallbackData, errorRetryCount: 3, ...(config ?? {}) },
    );

const setImage = async (uuid: string, image: string): Promise<void> => {
    await http.put(`/api/client/servers/${uuid}/settings/docker-image`, { docker_image: image });
};

const changeEgg = async (uuid: string, eggId: number, deleteFiles: boolean = false): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/settings/change-egg`, { egg_id: eggId, delete_files: deleteFiles });
};

const updateStartupVariable = async (uuid: string, key: string, value: string): Promise<[EggVariable, string]> => {
    const { data } = await http.put(`/api/client/servers/${uuid}/startup/variable`, { key, value });

    return [Transformers.toEggVariable(data), data.meta.startup_command];
};

export { getServerStartup, setImage, changeEgg, updateStartupVariable };
