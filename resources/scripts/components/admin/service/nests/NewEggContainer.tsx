import type { FormikHelpers } from 'formik';
import { Form, Formik, useFormikContext } from 'formik';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import tw from 'twin.macro';
import { array, object, string } from 'yup';

import createEgg from '@/api/routes/admin/eggs/createEgg';
import type { Egg as EggType } from '@/api/routes/admin/egg';
import { searchEggs } from '@/api/routes/admin/egg';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { EggProcessContainer } from '@admin/service/nests/eggs/EggSettingsContainer';
import type { EggProcessContainerRef } from '@admin/service/nests/eggs/EggSettingsContainer';
import DockerImageManager from '@admin/service/nests/eggs/DockerImageManager';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import Field from '@/elements/Field';
import Label from '@/elements/Label';
import Input from '@/elements/Input';

interface Values {
    name: string;
    description: string;
    startup: string;
    dockerImages: string;
    configStop: string;
    configStartup: string;
    configFiles: string;
    configFrom: number | null;
    copyScriptFrom: number | null;
    updateUrl: string;
    features: string[];
    fileDenylist: string[];
    forceOutgoingIp: boolean;
    scriptContainer: string;
    scriptEntry: string;
    scriptInstall: string;
    scriptIsPrivileged: boolean;
}

const STEPS = ['Basic', 'Runtime', 'Process', 'Advanced', 'Review'];

function StepIndicator({ step }: { step: number }) {
    return (
        <div css={tw`grid grid-cols-5 gap-3 mb-6`}>
            {STEPS.map((label, index) => (
                <div
                    key={label}
                    css={tw`text-center text-sm rounded px-2 py-2 border`}
                    style={{
                        borderColor: index <= step ? '#5ea3ff' : '#3f3f46',
                        color: index <= step ? '#dbeafe' : '#a1a1aa',
                        backgroundColor: index <= step ? 'rgba(59,130,246,0.2)' : 'rgba(39,39,42,0.6)',
                    }}
                >
                    {index + 1}. {label}
                </div>
            ))}
        </div>
    );
}

function BasicStep({ inheritanceOptions }: { inheritanceOptions: EggType[] }) {
    const { values, setFieldValue } = useFormikContext<Values>();

    return (
        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
            <AdminBox title={'Basic Information'}>
                <Field id={'name'} name={'name'} label={'Name'} type={'text'} css={tw`mb-6`} />
                <Field id={'description'} name={'description'} label={'Description'} type={'text'} css={tw`mb-2`} />
            </AdminBox>

            <AdminBox title={'Inheritance'}>
                <div css={tw`mb-4`}>
                    <Label htmlFor={'configFrom'}>Inherit Configuration From</Label>
                    <select
                        id={'configFrom'}
                        value={values.configFrom ?? ''}
                        onChange={e => setFieldValue('configFrom', e.currentTarget.value ? Number(e.currentTarget.value) : null)}
                        css={tw`w-full mt-2 rounded px-3 py-2 bg-neutral-900 border border-neutral-700 text-neutral-100`}
                    >
                        <option value={''}>None</option>
                        {inheritanceOptions.map(opt => (
                            <option key={opt.id} value={opt.id}>
                                {opt.name} (#{opt.id})
                            </option>
                        ))}
                    </select>
                </div>

                <div css={tw`mb-2`}>
                    <Label htmlFor={'copyScriptFrom'}>Inherit Script From</Label>
                    <select
                        id={'copyScriptFrom'}
                        value={values.copyScriptFrom ?? ''}
                        onChange={e =>
                            setFieldValue('copyScriptFrom', e.currentTarget.value ? Number(e.currentTarget.value) : null)
                        }
                        css={tw`w-full mt-2 rounded px-3 py-2 bg-neutral-900 border border-neutral-700 text-neutral-100`}
                    >
                        <option value={''}>None</option>
                        {inheritanceOptions.map(opt => (
                            <option key={opt.id} value={opt.id}>
                                {opt.name} (#{opt.id})
                            </option>
                        ))}
                    </select>
                </div>
            </AdminBox>
        </div>
    );
}

function RuntimeStep() {
    return (
        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
            <AdminBox title={'Runtime'}>
                <Field id={'startup'} name={'startup'} label={'Startup Command'} type={'text'} css={tw`mb-6`} />
                <Field id={'configStop'} name={'configStop'} label={'Stop Command'} type={'text'} css={tw`mb-6`} />
                <Field id={'scriptContainer'} name={'scriptContainer'} label={'Script Container'} type={'text'} css={tw`mb-6`} />
                <Field id={'scriptEntry'} name={'scriptEntry'} label={'Script Entry'} type={'text'} css={tw`mb-2`} />
            </AdminBox>

            <AdminBox title={'Docker Images'}>
                <DockerImageManager name={'dockerImages'} />
            </AdminBox>
        </div>
    );
}

function AdvancedStep() {
    const { values, setFieldValue } = useFormikContext<Values>();
    const [denyEntry, setDenyEntry] = useState('');

    const addDeny = () => {
        const next = denyEntry.trim();
        if (!next || values.fileDenylist.includes(next)) {
            return;
        }

        setFieldValue('fileDenylist', [...values.fileDenylist, next]);
        setDenyEntry('');
    };

    const toggleFeature = (feature: string) => {
        if (values.features.includes(feature)) {
            setFieldValue(
                'features',
                values.features.filter(f => f !== feature),
            );
            return;
        }

        setFieldValue('features', [...values.features, feature]);
    };

    return (
        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
            <AdminBox title={'Features & Networking'}>
                <Field id={'updateUrl'} name={'updateUrl'} label={'Update URL'} type={'text'} css={tw`mb-4`} />

                <label css={tw`flex items-center gap-3 mb-3`}>
                    <input
                        type={'checkbox'}
                        checked={values.forceOutgoingIp}
                        onChange={e => setFieldValue('forceOutgoingIp', e.currentTarget.checked)}
                    />
                    <span>Force Outgoing IP</span>
                </label>

                <label css={tw`flex items-center gap-3 mb-4`}>
                    <input
                        type={'checkbox'}
                        checked={values.scriptIsPrivileged}
                        onChange={e => setFieldValue('scriptIsPrivileged', e.currentTarget.checked)}
                    />
                    <span>Script Is Privileged</span>
                </label>

                <div css={tw`flex gap-2`}>
                    <Button type={'button'} onClick={() => toggleFeature('eula')}>
                        {values.features.includes('eula') ? 'Disable' : 'Enable'} EULA
                    </Button>
                    <Button type={'button'} onClick={() => toggleFeature('fastdl')}>
                        {values.features.includes('fastdl') ? 'Disable' : 'Enable'} FastDL
                    </Button>
                </div>
            </AdminBox>

            <AdminBox title={'File Denylist'}>
                <div css={tw`flex gap-2`}>
                    <Input
                        type={'text'}
                        value={denyEntry}
                        onChange={e => setDenyEntry(e.currentTarget.value)}
                        placeholder={'forbidden-file.jar'}
                    />
                    <Button type={'button'} onClick={addDeny}>
                        Add
                    </Button>
                </div>
                <div css={tw`mt-3 flex flex-wrap gap-2`}>
                    {values.fileDenylist.map(item => (
                        <button
                            key={item}
                            type={'button'}
                            onClick={() => setFieldValue('fileDenylist', values.fileDenylist.filter(v => v !== item))}
                            css={tw`text-xs rounded px-2 py-1 bg-neutral-900 border border-neutral-700 hover:border-neutral-500`}
                        >
                            {item} x
                        </button>
                    ))}
                </div>
            </AdminBox>
        </div>
    );
}

function ReviewStep() {
    const { values } = useFormikContext<Values>();

    return (
        <AdminBox title={'Review'}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3 text-sm`}>
                <div>Name: {values.name || '-'}</div>
                <div>Startup: {values.startup || '-'}</div>
                <div>Config Parent: {values.configFrom || 'None'}</div>
                <div>Script Parent: {values.copyScriptFrom || 'None'}</div>
                <div>Features: {values.features.length ? values.features.join(', ') : 'None'}</div>
                <div>Denylist Entries: {values.fileDenylist.length}</div>
            </div>
        </AdminBox>
    );
}

export default function NewEggContainer() {
    const navigate = useNavigate();
    const params = useParams<{ nestId: string }>();
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const ref = useRef<EggProcessContainerRef>();
    const [step, setStep] = useState(0);
    const [inheritanceOptions, setInheritanceOptions] = useState<EggType[]>([]);

    useEffect(() => {
        const nestId = Number(params.nestId);
        if (!nestId) {
            return;
        }

        searchEggs(nestId, { perPage: 200 })
            .then(setInheritanceOptions)
            .catch(() => setInheritanceOptions([]));
    }, [params.nestId]);

    const submit = async (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('egg:create');

        const nestId = Number(params.nestId);
        values.configStartup = (await ref.current?.getStartupConfiguration()) || values.configStartup;
        values.configFiles = (await ref.current?.getFilesConfiguration()) || values.configFiles;

        const dockerImages: Record<string, string> = {};
        values.dockerImages.split('\n').forEach(line => {
            const [imageRaw, aliasRaw] = line.split('|');
            const image = (imageRaw || '').trim();
            if (!image) {
                return;
            }

            dockerImages[(aliasRaw || image).trim()] = image;
        });

        createEgg({
            ...values,
            dockerImages,
            nestId,
            updateUrl: values.updateUrl || null,
        })
            .then(egg => navigate(`/admin/nests/${nestId}/eggs/${egg.id}`))
            .catch(error => {
                clearAndAddHttpError({ key: 'egg:create', error });
            })
            .then(() => setSubmitting(false));
    };

    return (
        <AdminContentBlock title={'New Egg'}>
            <div css={tw`w-full flex flex-col gap-2 sm:flex-row sm:items-center mb-6`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>New Egg Wizard</h2>
                    <p css={tw`hidden md:block text-base text-neutral-400`}>Create an egg using a guided five-step flow.</p>
                </div>
            </div>

            <FlashMessageRender key={'egg:create'} css={tw`mb-4`} />

            <Formik
                onSubmit={submit}
                initialValues={{
                    name: '',
                    description: '',
                    startup: '',
                    dockerImages: '',
                    configStop: 'stop',
                    configStartup: JSON.stringify({ done: [], strip_ansi: false, user_interaction: [] }, null, 2),
                    configFiles: '{}',
                    configFrom: null,
                    copyScriptFrom: null,
                    updateUrl: '',
                    features: [],
                    fileDenylist: [],
                    forceOutgoingIp: false,
                    scriptContainer: 'ghcr.io/pterodactyl/installers:debian',
                    scriptEntry: '/bin/bash',
                    scriptInstall: '',
                    scriptIsPrivileged: false,
                }}
                validationSchema={object().shape({
                    name: string().required('An egg name is required.').min(1).max(191),
                    startup: string().required('A startup command is required.'),
                    dockerImages: string()
                        .required('At least one Docker image is required.')
                        .test('valid-images', 'Each line must be in image|alias format.', value =>
                            (value || '')
                                .split('\n')
                                .filter(l => l.trim().length > 0)
                                .every(l => l.includes('|') && l.split('|')[0].trim().length > 0),
                        ),
                    configStop: string().required('A stop command is required.'),
                    features: array().of(string()),
                })}
            >
                {({ isSubmitting, isValid }) => (
                    <Form>
                        <StepIndicator step={step} />

                        {step === 0 && <BasicStep inheritanceOptions={inheritanceOptions} />}
                        {step === 1 && <RuntimeStep />}
                        {step === 2 && <EggProcessContainer ref={ref} css={tw`mb-6`} />}
                        {step === 3 && <AdvancedStep />}
                        {step === 4 && <ReviewStep />}

                        <div css={tw`bg-zinc-800 rounded shadow-md py-3 px-6 mb-16 mt-6`}>
                            <div css={tw`flex flex-row gap-3`}>
                                <Button type={'button'} variant={'secondary'} disabled={step < 1} onClick={() => setStep(step - 1)}>
                                    Back
                                </Button>

                                {step < STEPS.length - 1 ? (
                                    <Button type={'button'} className={'ml-auto'} onClick={() => setStep(step + 1)}>
                                        Continue
                                    </Button>
                                ) : (
                                    <Button type="submit" className={'ml-auto'} disabled={isSubmitting || !isValid}>
                                        Create Egg
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Form>
                )}
            </Formik>
        </AdminContentBlock>
    );
}
