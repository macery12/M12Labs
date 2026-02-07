import type { Actions } from 'easy-peasy';
import { useStoreActions } from 'easy-peasy';
import type { FormikHelpers } from 'formik';
import { Form, Formik, useField, useFormikContext } from 'formik';
import { useEffect, useState } from 'react';
import { object } from 'yup';
import tw from 'twin.macro';

import type { Egg, EggVariable, LoadedEgg } from '@/api/routes/admin/egg';
import { getEgg } from '@/api/routes/admin/egg';
import type { Server } from '@/api/routes/admin/server';
import { useServerFromRoute } from '@/api/routes/admin/server';
import type { Values } from '@/api/routes/admin/servers/updateServerStartup';
import updateServerStartup from '@/api/routes/admin/servers/updateServerStartup';
import updateServer from '@/api/routes/admin/servers/updateServer';
import EggSelect from '@admin/management/servers/EggSelect';
import NestSelector from '@admin/management/servers/NestSelector';
import FormikSwitch from '@/elements/FormikSwitch';
import { Button } from '@/elements/button';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import Label from '@/elements/Label';
import type { ApplicationStore } from '@/state';
import { WithRelationships } from '@/api/routes/admin';
import { useStoreState } from '@/state/hooks';
import { faLayerGroup, faCogs } from '@fortawesome/free-solid-svg-icons';
import NetworkingBox from '@admin/management/servers/settings/NetworkingBox';
import OwnerSelect from '@admin/management/servers/OwnerSelect';

function ServerStartupLineContainer({ egg, server }: { egg?: Egg; server: Server }) {
    const { isSubmitting, setFieldValue } = useFormikContext();

    useEffect(() => {
        if (egg === undefined) {
            return;
        }

        if (server.eggId === egg.id) {
            setFieldValue('image', server.container.image);
            setFieldValue('startup', server.container.startup || '');
            return;
        }

        // Whenever the egg is changed, set the server's startup command to the egg's default.
        setFieldValue('image', Object.values(egg.dockerImages)[0] ?? '');
        setFieldValue('startup', '');
    }, [egg]);

    return (
        <AdminBox title={'Startup Command'} className="relative w-full">
            <SpinnerOverlay visible={isSubmitting} />

            <div className="mb-3">
                <Field
                    id={'startup'}
                    name={'startup'}
                    label={'Startup Command'}
                    type={'text'}
                    description={
                        "Edit your server's startup command here. The following variables are available by default: {{SERVER_MEMORY}}, {{SERVER_IP}}, and {{SERVER_PORT}}."
                    }
                    placeholder={egg?.startup || ''}
                />
            </div>

            <div>
                <Label>Default Startup Command</Label>
                <Input value={egg?.startup || ''} readOnly />
            </div>
        </AdminBox>
    );
}

export function ServerServiceContainer({
    selectedEggId,
    setEgg,
    nestId: _nestId,
    noToggle,
}: {
    selectedEggId?: number;
    setEgg: (value: WithRelationships<Egg, 'variables'> | undefined) => void;
    nestId: number;
    noToggle?: boolean;
}) {
    const { isSubmitting } = useFormikContext();

    const [nestId, setNestId] = useState<number>(_nestId);

    return (
        <AdminBox title={'Service Configuration'} isLoading={isSubmitting} className="w-full" icon={faLayerGroup}>
            <div className="mb-3">
                <NestSelector selectedNestId={nestId} onNestSelect={setNestId} />
            </div>
            <div className="mb-3">
                <EggSelect nestId={nestId} selectedEggId={selectedEggId} onEggSelect={setEgg} />
            </div>
            {!noToggle && (
                <div className="rounded border border-neutral-900 bg-neutral-800 p-3 shadow-inner">
                    <FormikSwitch name={'skipScripts'} label={'Skip Egg Install Script'} description={'Soon™'} />
                </div>
            )}
        </AdminBox>
    );
}

export function ServerImageContainer() {
    const { isSubmitting } = useFormikContext();

    return (
        <AdminBox title={'Image Configuration'} className="relative w-full">
            <SpinnerOverlay visible={isSubmitting} />

            <div className="md:flex md:w-full md:flex-col">
                <div>
                    {/* TODO: make this a proper select but allow a custom image to be specified if needed. */}
                    <Field id={'image'} name={'image'} label={'Docker Image'} type={'text'} />
                </div>
            </div>
        </AdminBox>
    );
}

export function ServerVariableContainer({ variable, value }: { variable: EggVariable; value?: string }) {
    const key = 'environment.' + variable.environmentVariable;

    const [, , { setValue, setTouched }] = useField<string | undefined>(key);

    const { isSubmitting } = useFormikContext();

    useEffect(() => {
        if (value === undefined) {
            return;
        }

        setValue(value);
        setTouched(true);
    }, [value]);

    return (
        <AdminBox className="relative w-full" title={<p className="text-sm uppercase">{variable.name}</p>}>
            <SpinnerOverlay visible={isSubmitting} />

            <Field
                id={key}
                name={key}
                type={'text'}
                placeholder={variable.defaultValue}
                description={variable.description}
            />
        </AdminBox>
    );
}

function ServerConfigurationForm({
    selectedEggId,
    egg,
    setEgg,
    server,
}: {
    selectedEggId?: number;
    egg?: LoadedEgg;
    setEgg: (value: LoadedEgg | undefined) => void;
    server: Server;
}) {
    const {
        isSubmitting,
        isValid,
        values: { environment },
    } = useFormikContext<Values>();

    const { secondary } = useStoreState(state => state.theme.data!.colors);

    return (
        <Form>
            <div className="mb-8 flex flex-col">
                {/* Basic Settings Section */}
                <div css={tw`mb-4`}>
                    <AdminBox icon={faCogs} title={'Server Information'} isLoading={isSubmitting}>
                        <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4 lg:gap-6`}>
                            <Field
                                id={'name'}
                                name={'name'}
                                label={'Server Name'}
                                type={'text'}
                                placeholder={'My Amazing Server'}
                            />
                            <Field id={'externalId'} name={'externalId'} label={'External Identifier'} type={'text'} />
                            <OwnerSelect selected={server?.relationships.user} />
                        </div>
                    </AdminBox>
                </div>

                {/* Network Configuration Section - Moved to top */}
                <div css={tw`mb-4`}>
                    <NetworkingBox />
                </div>

                {/* Startup Configuration Section */}
                <div css={tw`mb-4`}>
                    <div className="mb-3 flex flex-row">
                        <ServerStartupLineContainer egg={egg} server={server} />
                    </div>

                    <div className="mb-3 grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
                        <div className="flex">
                            <ServerServiceContainer
                                selectedEggId={selectedEggId}
                                setEgg={setEgg}
                                nestId={server.nestId}
                            />
                        </div>

                        <div className="flex">
                            <ServerImageContainer />
                        </div>
                    </div>

                    {egg?.relationships.variables && egg.relationships.variables.length > 0 && (
                        <div className="grid grid-cols-1 gap-y-3 gap-x-6 md:grid-cols-2">
                            {/* This ensures that no variables are rendered unless the environment has a value for the variable. */}
                            {egg.relationships.variables
                                ?.filter(
                                    v => Object.keys(environment).find(e => e === v.environmentVariable) !== undefined,
                                )
                                .map((v, i) => (
                                    <ServerVariableContainer
                                        key={i}
                                        variable={v}
                                        value={
                                            server.relationships.variables?.find(
                                                v2 =>
                                                    v.eggId === v2.eggId &&
                                                    v.environmentVariable === v2.environmentVariable,
                                            )?.serverValue
                                        }
                                    />
                                ))}
                        </div>
                    )}
                </div>

                <div className="mt-4 rounded py-2 pr-6 shadow-md" style={{ backgroundColor: secondary }}>
                    <div className="flex flex-row">
                        <Button type="submit" className="ml-auto" disabled={isSubmitting || !isValid}>
                            Save Changes
                        </Button>
                    </div>
                </div>
            </div>
        </Form>
    );
}

export default () => {
    const { data: server, mutate } = useServerFromRoute();
    const { clearFlashes, clearAndAddHttpError } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes,
    );
    const [egg, setEgg] = useState<LoadedEgg | undefined>(undefined);

    useEffect(() => {
        if (!server) {
            return;
        }

        getEgg(server.eggId)
            .then(egg => setEgg(egg))
            .catch(error => console.error(error));
    }, [server?.eggId]);

    if (!server) return null;

    const submit = async (
        values: Values & { name: string; externalId: string; ownerId: number },
        { setSubmitting }: FormikHelpers<Values & { name: string; externalId: string; ownerId: number }>,
    ) => {
        clearFlashes('server');

        try {
            // Update startup configuration
            await updateServerStartup(server.id, {
                startup: values.startup,
                environment: values.environment,
                image: values.image,
                eggId: values.eggId,
                skipScripts: values.skipScripts,
            });

            // Update basic server settings
            await updateServer(server.id, {
                name: values.name,
                externalId: values.externalId,
                ownerId: values.ownerId,
                limits: server.limits,
                featureLimits: server.featureLimits,
                allocationId: server.allocationId,
                addAllocations: [],
                removeAllocations: [],
            });

            await mutate();
        } catch (error) {
            console.error(error);
            clearAndAddHttpError({ key: 'server', error });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                name: server.name,
                externalId: server.externalId || '',
                ownerId: server.ownerId,
                startup: server.container.startup || '',
                environment: {} as Record<string, any>,
                image: server.container.image,
                eggId: server.eggId,
                skipScripts: false,
            }}
            validationSchema={object().shape({})}
        >
            <ServerConfigurationForm
                selectedEggId={egg?.id ?? server.eggId}
                egg={egg}
                setEgg={setEgg}
                server={server as Server}
            />
        </Formik>
    );
};
