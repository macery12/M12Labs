import { faNetworkWired, faBalanceScale, faCogs, faConciergeBell } from '@fortawesome/free-solid-svg-icons';
import type { FormikHelpers } from 'formik';
import { Form, Formik, useFormikContext } from 'formik';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import tw from 'twin.macro';
import { object } from 'yup';

import type { Egg } from '@/api/routes/admin/egg';
import type { CreateServerRequest } from '@/api/routes/admin/servers/createServer';
import createServer from '@/api/routes/admin/servers/createServer';
import type { Node } from '@/api/routes/admin/node';
import AdminBox from '@/elements/AdminBox';
import NodeSelect from '@admin/management/servers/NodeSelect';
import {
    ServerImageContainer,
    ServerServiceContainer,
    ServerVariableContainer,
} from '@admin/management/servers/ServerStartupContainer';
import OwnerSelect from '@admin/management/servers/OwnerSelect';
import { Button } from '@/elements/button';
import Field from '@/elements/Field';
import FormikSwitch from '@/elements/FormikSwitch';
import Label from '@/elements/Label';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { WithRelationships } from '@/api/routes/admin';
import { AsyncSelectField } from '@/elements/SelectField';
import type { Option } from '@/elements/SelectField';
import getAllocations from '@/api/routes/admin/nodes/getAllocations';
import { Alert } from '@/elements/alert';
import CheckoutStepper from '@/components/account/billing/order/CheckoutStepper';
import { useStoreState } from '@/state/hooks';
import classNames from 'classnames';

// Resource preset options
const MEMORY_PRESETS = [
    { label: '2 GB', value: 2048 },
    { label: '4 GB', value: 4096 },
    { label: '6 GB', value: 6144 },
    { label: '8 GB', value: 8192 },
    { label: '10 GB', value: 10240 },
];

const DISK_PRESETS = [
    { label: '10 GB', value: 10240 },
    { label: '25 GB', value: 25600 },
    { label: '50 GB', value: 51200 },
    { label: '100 GB', value: 102400 },
];

interface Step {
    id: number;
    name: string;
    status: 'complete' | 'current' | 'upcoming';
}

function InternalForm() {
    const {
        isSubmitting,
        isValid,
        setFieldValue,
        values,
        values: { environment },
    } = useFormikContext<CreateServerRequest>();

    const [egg, setEgg] = useState<WithRelationships<Egg, 'variables'> | undefined>(undefined);
    const [node, setNode] = useState<Node | null>(null);
    const [currentStep, setCurrentStep] = useState<number>(1);
    const { colors } = useStoreState(state => state.theme.data!);

    useEffect(() => {
        if (egg === undefined) {
            return;
        }

        setFieldValue('eggId', egg.id);
        setFieldValue('startup', '');
        setFieldValue('image', Object.values(egg.dockerImages)[0] ?? '');
    }, [egg]);

    useEffect(() => {
        if (node !== null) {
            setFieldValue('nodeId', node.id);
        }
    }, [node]);

    const loadOptions = async (inputValue: string, callback: (options: Option[]) => void) => {
        if (!node) {
            callback([] as Option[]);
            return;
        }

        const allocations = await getAllocations(node.id, { search: inputValue, server_id: '0' });

        callback(
            allocations.map(a => {
                return { value: a.id.toString(), label: a.getDisplayText() };
            }),
        );
    };

    const getWizardSteps = (): Step[] => {
        return [
            {
                id: 1,
                name: 'Basic Info',
                status: currentStep > 1 ? 'complete' : currentStep === 1 ? 'current' : 'upcoming',
            },
            {
                id: 2,
                name: 'Service',
                status: currentStep > 2 ? 'complete' : currentStep === 2 ? 'current' : 'upcoming',
            },
            {
                id: 3,
                name: 'Resources',
                status: currentStep > 3 ? 'complete' : currentStep === 3 ? 'current' : 'upcoming',
            },
            {
                id: 4,
                name: 'Advanced',
                status: currentStep > 4 ? 'complete' : currentStep === 4 ? 'current' : 'upcoming',
            },
            {
                id: 5,
                name: 'Review',
                status: currentStep === 5 ? 'current' : 'upcoming',
            },
        ];
    };

    const isStepValid = (step: number): boolean => {
        switch (step) {
            case 1:
                return !!(values.name && values.ownerId && node);
            case 2:
                return !!(egg && values.eggId);
            case 3:
                return !!(values.limits.memory > 0 && values.limits.disk > 0);
            case 4:
                return !!values.allocation.default;
            case 5:
                return true;
            default:
                return false;
        }
    };

    const goToNextStep = () => {
        if (currentStep < 5 && isStepValid(currentStep)) {
            setCurrentStep(currentStep + 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const goToPreviousStep = () => {
        if (currentStep > 1) {
            setCurrentStep(currentStep - 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const renderStep = () => {
        switch (currentStep) {
            case 1:
                return (
                    <div className="space-y-6">
                        <AdminBox icon={faCogs} title={'Basic Settings'} isLoading={isSubmitting}>
                            <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4 lg:gap-6`}>
                                <Field
                                    id={'name'}
                                    name={'name'}
                                    label={'Server Name'}
                                    type={'text'}
                                    placeholder={'My Amazing Server'}
                                />
                                <Field id={'externalId'} name={'externalId'} label={'External Identifier'} type={'text'} />
                                <div className="xl:col-span-2">
                                    <OwnerSelect />
                                </div>
                                <div className="xl:col-span-2">
                                    <Label>Node</Label>
                                    <NodeSelect node={node} setNode={setNode} />
                                </div>
                                <div className="rounded border border-neutral-900 bg-neutral-800 p-4 shadow-inner xl:col-span-2">
                                    <FormikSwitch
                                        name={'startOnCompletion'}
                                        label={'Start after installation'}
                                        description={'Should the server be automatically started after it has been installed?'}
                                    />
                                </div>
                            </div>
                        </AdminBox>
                        <Field
                            id={'description'}
                            name={'description'}
                            label={'Description'}
                            type={'text'}
                            placeholder={'A brief description of this server'}
                        />
                    </div>
                );
            case 2:
                return (
                    <div className="space-y-6">
                        <ServerServiceContainer selectedEggId={egg?.id} setEgg={setEgg} nestId={0} />
                        <ServerImageContainer />
                    </div>
                );
            case 3:
                return (
                    <div className="space-y-6">
                        <AdminBox icon={faBalanceScale} title={'Resource Allocation'} isLoading={isSubmitting}>
                            <div css={tw`grid grid-cols-1 gap-6`}>
                                {/* Memory Section */}
                                <div>
                                    <Label>Memory Limit (MB)</Label>
                                    <p className="mb-3 text-sm text-neutral-400">
                                        Select a preset or enter a custom value
                                    </p>
                                    <div className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-5">
                                        {MEMORY_PRESETS.map(preset => (
                                            <button
                                                key={preset.value}
                                                type="button"
                                                onClick={() => setFieldValue('limits.memory', preset.value)}
                                                className={classNames(
                                                    'rounded border-2 px-4 py-2 text-center font-medium transition-all',
                                                    values.limits.memory === preset.value
                                                        ? 'border-primary-500 bg-primary-500/20 text-primary-400'
                                                        : 'border-neutral-600 bg-neutral-700 text-neutral-300 hover:border-neutral-500',
                                                )}
                                                style={
                                                    values.limits.memory === preset.value
                                                        ? { borderColor: colors.primary, color: colors.primary }
                                                        : {}
                                                }
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>
                                    <Field
                                        id={'limits.memory'}
                                        name={'limits.memory'}
                                        label={'Custom Memory (MB)'}
                                        type={'number'}
                                        description={'The maximum amount of memory allowed for this container. Setting this to 0 will allow unlimited memory.'}
                                    />
                                </div>

                                {/* Disk Section */}
                                <div>
                                    <Label>Disk Space (MB)</Label>
                                    <p className="mb-3 text-sm text-neutral-400">
                                        Select a preset or enter a custom value
                                    </p>
                                    <div className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-4">
                                        {DISK_PRESETS.map(preset => (
                                            <button
                                                key={preset.value}
                                                type="button"
                                                onClick={() => setFieldValue('limits.disk', preset.value)}
                                                className={classNames(
                                                    'rounded border-2 px-4 py-2 text-center font-medium transition-all',
                                                    values.limits.disk === preset.value
                                                        ? 'border-primary-500 bg-primary-500/20 text-primary-400'
                                                        : 'border-neutral-600 bg-neutral-700 text-neutral-300 hover:border-neutral-500',
                                                )}
                                                style={
                                                    values.limits.disk === preset.value
                                                        ? { borderColor: colors.primary, color: colors.primary }
                                                        : {}
                                                }
                                            >
                                                {preset.label}
                                            </button>
                                        ))}
                                    </div>
                                    <Field
                                        id={'limits.disk'}
                                        name={'limits.disk'}
                                        label={'Custom Disk Space (MB)'}
                                        type={'number'}
                                        description={'This server will not be allowed to boot if it is using more than this amount of space.'}
                                    />
                                </div>

                                {/* CPU Section */}
                                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                                    <Field
                                        id={'limits.cpu'}
                                        name={'limits.cpu'}
                                        label={'CPU Limit (%)'}
                                        type={'number'}
                                        description={'Each thread on the system is considered to be 100%. Setting this to 0 will allow the server to use CPU time without restriction.'}
                                    />
                                    <Field
                                        id={'limits.threads'}
                                        name={'limits.threads'}
                                        label={'CPU Pinning'}
                                        type={'text'}
                                        description={'Advanced: Enter the specific CPU cores that this server can run on, or leave blank to allow all cores.'}
                                    />
                                </div>

                                {/* Other Resource Limits */}
                                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                                    <Field id={'limits.swap'} name={'limits.swap'} label={'Swap Limit (MB)'} type={'number'} />
                                    <Field
                                        id={'limits.io'}
                                        name={'limits.io'}
                                        label={'Block IO Proportion'}
                                        type={'number'}
                                        description={'Advanced: The IO performance of this server relative to other running containers. Value should be between 10 and 1000.'}
                                    />
                                </div>

                                <div css={tw`bg-neutral-800 border border-neutral-900 shadow-inner p-4 rounded`}>
                                    <FormikSwitch
                                        name={'limits.oomKiller'}
                                        label={'Out of Memory Killer'}
                                        description={'Enabling the Out of Memory Killer may cause server processes to exit unexpectedly.'}
                                    />
                                </div>
                            </div>
                        </AdminBox>
                    </div>
                );
            case 4:
                return (
                    <div className="space-y-6">
                        <AdminBox icon={faNetworkWired} title="Networking" isLoading={isSubmitting}>
                            <div className="grid grid-cols-1 gap-4 lg:gap-6">
                                <div>
                                    <Label htmlFor={'allocation.default'}>Primary Allocation</Label>
                                    {!node ? (
                                        <Alert type={'info'}>Select a node to view allocations.</Alert>
                                    ) : (
                                        <AsyncSelectField
                                            id={'allocation.default'}
                                            name={'allocation.default'}
                                            loadOptions={loadOptions}
                                        />
                                    )}
                                </div>
                            </div>
                        </AdminBox>
                        <AdminBox icon={faConciergeBell} title={'Feature Limits'} isLoading={isSubmitting}>
                            <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4 lg:gap-6`}>
                                <Field
                                    id={'featureLimits.allocations'}
                                    name={'featureLimits.allocations'}
                                    label={'Allocation Limit'}
                                    type={'number'}
                                    description={'The total number of allocations a user is allowed to create for this server.'}
                                />
                                <Field
                                    id={'featureLimits.backups'}
                                    name={'featureLimits.backups'}
                                    label={'Backup Limit'}
                                    type={'number'}
                                    description={'The total number of backups that can be created for this server.'}
                                />
                                <Field
                                    id={'featureLimits.databases'}
                                    name={'featureLimits.databases'}
                                    label={'Database Limit'}
                                    type={'number'}
                                    description={'The total number of databases a user is allowed to create for this server.'}
                                />
                                <Field
                                    id={'featureLimits.subusers'}
                                    name={'featureLimits.subusers'}
                                    label={'Subuser Limit'}
                                    type={'number'}
                                    description={'The total number of subusers that can be added to this server.'}
                                />
                            </div>
                        </AdminBox>
                    </div>
                );
            case 5:
                return (
                    <div className="space-y-6">
                        <AdminBox title={'Startup Command'} className="relative w-full">
                            <SpinnerOverlay visible={isSubmitting} />
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
                        </AdminBox>

                        {egg?.relationships.variables && egg.relationships.variables.length > 0 && (
                            <AdminBox title={'Environment Variables'}>
                                <div className="grid grid-cols-1 gap-y-6 gap-x-8 md:grid-cols-2">
                                    {egg.relationships.variables
                                        ?.filter(v => Object.keys(environment).find(e => e === v.environmentVariable) !== undefined)
                                        .map((v, i) => (
                                            <ServerVariableContainer key={i} variable={v} />
                                        ))}
                                </div>
                            </AdminBox>
                        )}

                        <AdminBox title={'Review Configuration'}>
                            <div className="space-y-4 text-sm">
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">Server Name:</span>
                                    <span className="font-medium text-neutral-200">{values.name || 'Not set'}</span>
                                </div>
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">Node:</span>
                                    <span className="font-medium text-neutral-200">{node?.name || 'Not set'}</span>
                                </div>
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">Service:</span>
                                    <span className="font-medium text-neutral-200">{egg?.name || 'Not set'}</span>
                                </div>
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">Memory:</span>
                                    <span className="font-medium text-neutral-200">{values.limits.memory} MB ({(values.limits.memory / 1024).toFixed(1)} GB)</span>
                                </div>
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">Disk:</span>
                                    <span className="font-medium text-neutral-200">{values.limits.disk} MB ({(values.limits.disk / 1024).toFixed(1)} GB)</span>
                                </div>
                                <div className="grid grid-cols-2 gap-4 border-b border-neutral-700 pb-3">
                                    <span className="text-neutral-400">CPU:</span>
                                    <span className="font-medium text-neutral-200">{values.limits.cpu}%</span>
                                </div>
                            </div>
                        </AdminBox>
                    </div>
                );
            default:
                return null;
        }
    };

    return (
        <Form>
            <CheckoutStepper steps={getWizardSteps()} />
            
            <div className="mb-8">
                {renderStep()}
            </div>

            <div className="flex justify-between rounded bg-neutral-700 px-4 py-3 shadow-md">
                <Button
                    type="button"
                    onClick={goToPreviousStep}
                    disabled={currentStep === 1}
                    className={currentStep === 1 ? 'invisible' : ''}
                >
                    Previous
                </Button>
                
                {currentStep < 5 ? (
                    <Button
                        type="button"
                        onClick={goToNextStep}
                        disabled={!isStepValid(currentStep)}
                    >
                        Next
                    </Button>
                ) : (
                    <Button type="submit" disabled={isSubmitting || !isValid}>
                        Create Server
                    </Button>
                )}
            </div>
        </Form>
    );
}

export default () => {
    const navigate = useNavigate();

    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const submit = (r: CreateServerRequest, { setSubmitting }: FormikHelpers<CreateServerRequest>) => {
        clearFlashes('server:create');

        createServer(r)
            .then(s => navigate(`/admin/servers/${s.id}`))
            .catch(error => clearAndAddHttpError({ key: 'server:create', error }))
            .then(() => setSubmitting(false));
    };

    return (
        <AdminContentBlock title={'New Server'}>
            <div css={tw`w-full flex flex-row items-center mb-8`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>New Server</h2>
                    <p
                        css={tw`hidden md:block text-base text-neutral-400 whitespace-nowrap overflow-ellipsis overflow-hidden`}
                    >
                        Add a new server to the panel.
                    </p>
                </div>
            </div>

            <FlashMessageRender byKey={'server:create'} css={tw`mb-4`} />

            <Formik
                onSubmit={submit}
                initialValues={
                    {
                        externalId: '',
                        name: '',
                        description: '',
                        ownerId: 0,
                        nodeId: 0,
                        limits: {
                            memory: 2048, // Default to 2GB
                            swap: 0,
                            disk: 10240, // Default to 10GB
                            io: 500,
                            cpu: 100, // Default to 100% CPU
                            threads: '',
                            oomKiller: true,
                        },
                        featureLimits: {
                            allocations: 1,
                            backups: 0,
                            databases: 0,
                            subusers: 0,
                        },
                        allocation: {
                            default: 0,
                            additional: [] as number[],
                        },
                        startup: '',
                        environment: [],
                        eggId: 0,
                        image: '',
                        skipScripts: false,
                        startOnCompletion: true,
                    } as CreateServerRequest
                }
                validationSchema={object().shape({})}
            >
                <InternalForm />
            </Formik>
        </AdminContentBlock>
    );
};
