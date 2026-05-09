import { LanguageDescription } from '@codemirror/language';
import { json } from '@codemirror/lang-json';
import { faDocker } from '@fortawesome/free-brands-svg-icons';
import {
    faCodeBranch,
    faEgg,
    faFireAlt,
    faInfoCircle,
    faMicrochip,
    faShieldAlt,
    faTerminal,
} from '@fortawesome/free-solid-svg-icons';
import type { FormikHelpers } from 'formik';
import { Form, Formik, useFormikContext } from 'formik';
import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import tw from 'twin.macro';
import { object, string } from 'yup';

import { searchEggs, useEggFromRoute } from '@/api/routes/admin/egg';
import type { Egg as EggType } from '@/api/routes/admin/egg';
import updateEgg from '@/api/routes/admin/eggs/updateEgg';
import AdminBox from '@/elements/AdminBox';
import EggDeleteButton from '@admin/service/nests/eggs/EggDeleteButton';
import EggExportButton from '@admin/service/nests/eggs/EggExportButton';
import DockerImageManager from '@admin/service/nests/eggs/DockerImageManager';
import { Button } from '@/elements/button';
import { Editor } from '@/elements/editor';
import Field, { TextareaField } from '@/elements/Field';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import Checkbox from '@/elements/inputs/Checkbox';

const FEATURE_OPTIONS = [
    { key: 'eula', name: 'EULA Popup', description: 'Prompt users to accept EULA rules.' },
    { key: 'fastdl', name: 'FastDL', description: 'Enable fast file download behavior where supported.' },
] as const;

const parseDenylist = (value: string): string[] => {
    return value
        .split('\n')
        .map(v => v.trim())
        .filter(v => v.length > 0);
};

const stringifyDenylist = (values: string[]): string => values.join('\n');

const toDockerImages = (value: string): Record<string, string> => {
    const dockerImages: Record<string, string> = {};

    value.split('\n').forEach(v => {
        const parts = v.trim().split('|');
        const image = parts[0] || '';
        if (image.length < 1) {
            return;
        }

        const alias = parts[1] || image;
        dockerImages[alias] = image;
    });

    return dockerImages;
};

function SectionHint({ text }: { text: string }) {
    return <p css={tw`text-xs text-neutral-400 mt-1 mb-4`}>{text}</p>;
}

function EggIdentitySection({ inheritanceOptions }: { inheritanceOptions: EggType[] }) {
    const { values } = useFormikContext<Values>();

    return (
        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6`}>
            <AdminBox icon={faInfoCircle} title={'Identity'}>
                <SectionHint text={'The name and description admins will use to identify this egg.'} />
                <Field id={'name'} name={'name'} label={'Name'} type={'text'} css={tw`mb-6`} />
                <Field id={'description'} name={'description'} label={'Description'} type={'text'} css={tw`mb-6`} />
                <Field id={'updateUrl'} name={'updateUrl'} label={'Update URL'} type={'text'} css={tw`mb-2`} />
            </AdminBox>

            <AdminBox icon={faCodeBranch} title={'Inheritance'}>
                <SectionHint text={'Reuse settings or scripts from another egg in this nest when needed.'} />

                <div css={tw`mb-6`}>
                    <Label htmlFor={'configFrom'}>Inherit Configuration From</Label>
                    <Field as={'select'} id={'configFrom'} name={'configFrom'} css={tw`w-full mt-2`}>
                        <option value={''}>None</option>
                        {inheritanceOptions.map(option => (
                            <option key={option.id} value={option.id}>
                                {option.name} (#{option.id})
                            </option>
                        ))}
                    </Field>
                </div>

                <div css={tw`mb-2`}>
                    <Label htmlFor={'copyScriptFrom'}>Inherit Install Script From</Label>
                    <Field as={'select'} id={'copyScriptFrom'} name={'copyScriptFrom'} css={tw`w-full mt-2`}>
                        <option value={''}>None</option>
                        {inheritanceOptions.map(option => (
                            <option key={option.id} value={option.id}>
                                {option.name} (#{option.id})
                            </option>
                        ))}
                    </Field>
                </div>

                <div css={tw`mt-6 p-3 rounded bg-neutral-900 border border-neutral-700 text-xs text-neutral-400`}>
                    Current links: config #{values.configFrom || 'none'} | script #{values.copyScriptFrom || 'none'}
                </div>
            </AdminBox>
        </div>
    );
}

function EggRuntimeSection() {
    const { values, setFieldValue } = useFormikContext<Values>();

    const hasFeature = (feature: string) => values.features.includes(feature);

    const toggleFeature = (feature: string) => {
        if (hasFeature(feature)) {
            setFieldValue(
                'features',
                values.features.filter(v => v !== feature),
            );
            return;
        }

        setFieldValue('features', [...values.features, feature]);
    };

    return (
        <AdminBox icon={faTerminal} title={'Runtime & Behavior'} css={tw`mb-6`}>
            <SectionHint text={'Define how the server starts, stops, and behaves in production.'} />

            <Field id={'startup'} name={'startup'} label={'Startup Command'} type={'text'} css={tw`mb-6`} />
            <Field id={'configStop'} name={'configStop'} label={'Stop Command'} type={'text'} css={tw`mb-6`} />

            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6 mb-6`}>
                <label css={tw`flex items-center gap-3`}>
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        id={'forceOutgoingIp'}
                        name={'forceOutgoingIp'}
                    />
                    <span css={tw`text-sm`}>Force Outgoing IP</span>
                </label>

                <label css={tw`flex items-center gap-3`}>
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        id={'scriptIsPrivileged'}
                        name={'scriptIsPrivileged'}
                    />
                    <span css={tw`text-sm`}>Install Script Is Privileged</span>
                </label>
            </div>

            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3`}>
                {FEATURE_OPTIONS.map(feature => (
                    <button
                        key={feature.key}
                        type={'button'}
                        onClick={() => toggleFeature(feature.key)}
                        css={tw`text-left rounded border border-neutral-700 hover:border-neutral-500 px-3 py-3 bg-neutral-900`}
                    >
                        <div css={tw`text-sm font-medium text-neutral-100`}>{feature.name}</div>
                        <div css={tw`text-xs text-neutral-400 mt-1`}>{feature.description}</div>
                        <div css={tw`text-xs mt-2`} style={{ color: hasFeature(feature.key) ? '#86efac' : '#fca5a5' }}>
                            {hasFeature(feature.key) ? 'Enabled' : 'Disabled'}
                        </div>
                    </button>
                ))}
            </div>
        </AdminBox>
    );
}

function EggInfrastructureSection() {
    return (
        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6`}>
            <AdminBox icon={faDocker} title={'Docker Images'}>
                <SectionHint text={'Add one or more images in image|alias format.'} />
                <DockerImageManager name={'dockerImages'} />
            </AdminBox>

            <AdminBox icon={faShieldAlt} title={'File Denylist'}>
                <SectionHint text={'One file path per line. These files will be blocked in file operations.'} />
                <TextareaField
                    id={'fileDenylistText'}
                    name={'fileDenylistText'}
                    label={'Denylisted Files'}
                    rows={10}
                />
            </AdminBox>
        </div>
    );
}

export function EggStartupContainer({ className }: { className?: string }) {
    return (
        <AdminBox icon={faTerminal} title={'Startup Command'} css={tw`mb-6`} className={className}>
            <Field id={'startup'} name={'startup'} label={'Startup Command'} type={'text'} css={tw`mb-1`} />
        </AdminBox>
    );
}

export function EggImageContainer() {
    return (
        <AdminBox icon={faDocker} title={'Docker Images'}>
            <DockerImageManager name={'dockerImages'} />
        </AdminBox>
    );
}

export function EggLifecycleContainer() {
    return (
        <AdminBox icon={faFireAlt} title={'Lifecycle'}>
            <Field id={'configStop'} name={'configStop'} label={'Stop Command'} type={'text'} css={tw`mb-4`} />

            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6 mb-2`}>
                <label css={tw`flex items-center gap-3`}>
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        id={'forceOutgoingIp'}
                        name={'forceOutgoingIp'}
                    />
                    <span css={tw`text-sm`}>Force Outgoing IP</span>
                </label>

                <label css={tw`flex items-center gap-3`}>
                    <Field
                        type="checkbox"
                        // @ts-expect-error checkbox rendering
                        as={Checkbox}
                        id={'scriptIsPrivileged'}
                        name={'scriptIsPrivileged'}
                    />
                    <span css={tw`text-sm`}>Install Script Is Privileged</span>
                </label>
            </div>
        </AdminBox>
    );
}

interface EggProcessSectionProps {
    className?: string;
}

export interface EggProcessContainerRef {
    getStartupConfiguration: () => Promise<string | null>;
    getFilesConfiguration: () => Promise<string | null>;
}

export const EggProcessContainer = forwardRef<any, EggProcessSectionProps>(function EggProcessContainer({ className }, ref) {
    const { isSubmitting, values } = useFormikContext<Values>();

    let fetchStartupConfiguration: (() => Promise<string>) | null = null;
    let fetchFilesConfiguration: (() => Promise<string>) | null = null;

    useImperativeHandle<EggProcessContainerRef, EggProcessContainerRef>(
        ref,
        () => ({
            getStartupConfiguration: async () => {
                if (fetchStartupConfiguration === null) {
                    return values.configStartup;
                }
                return await fetchStartupConfiguration();
            },

            getFilesConfiguration: async () => {
                if (fetchFilesConfiguration === null) {
                    return values.configFiles;
                }
                return await fetchFilesConfiguration();
            },
        }),
        [fetchStartupConfiguration, fetchFilesConfiguration, values.configStartup, values.configFiles],
    );

    return (
        <AdminBox icon={faMicrochip} title={'Process Configuration'} css={tw`mb-6`} className={className}>
            <SpinnerOverlay visible={isSubmitting} />
            <SectionHint text={'These JSON blocks are used by Wings to detect startup state and parse file updates.'} />

            <div css={tw`mb-5`}>
                <Label>Startup Configuration</Label>
                <Editor
                    childClassName={tw`h-40 rounded`}
                    initialContent={values.configStartup}
                    fetchContent={value => {
                        fetchStartupConfiguration = value;
                    }}
                    language={LanguageDescription.of({ name: 'json', support: json() })}
                />
            </div>

            <div css={tw`mb-1`}>
                <Label>Configuration Files</Label>
                <Editor
                    childClassName={tw`h-56 rounded`}
                    initialContent={values.configFiles}
                    fetchContent={value => {
                        fetchFilesConfiguration = value;
                    }}
                    language={LanguageDescription.of({ name: 'json', support: json() })}
                />
            </div>
        </AdminBox>
    );
});

function EggDetailsAside() {
    const { data: egg } = useEggFromRoute();

    if (!egg) {
        return null;
    }

    return (
        <AdminBox icon={faEgg} title={'Quick Reference'}>
            <div css={tw`space-y-4`}>
                <div>
                    <Label>Egg ID</Label>
                    <Input type={'text'} value={egg.id} readOnly />
                </div>
                <div>
                    <Label>UUID</Label>
                    <Input type={'text'} value={egg.uuid} readOnly />
                </div>
                <div>
                    <Label>Author</Label>
                    <Input type={'text'} value={egg.author} readOnly />
                </div>
                <div>
                    <Label>Created</Label>
                    <Input type={'text'} value={egg.createdAt.toLocaleString()} readOnly />
                </div>
            </div>
        </AdminBox>
    );
}

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
    fileDenylistText: string;
    forceOutgoingIp: boolean;
    scriptIsPrivileged: boolean;
}

export default function EggSettingsContainer() {
    const navigate = useNavigate();
    const ref = useRef<EggProcessContainerRef>();

    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { secondary } = useStoreState(state => state.theme.data!.colors);
    const { data: egg } = useEggFromRoute();

    const [inheritanceOptions, setInheritanceOptions] = useState<EggType[]>([]);

    useEffect(() => {
        if (!egg) {
            return;
        }

        searchEggs(egg.nestId, { perPage: 200 })
            .then(items => setInheritanceOptions(items.filter(item => item.id !== egg.id)))
            .catch(() => setInheritanceOptions([]));
    }, [egg?.id]);

    if (!egg) {
        return null;
    }

    const submit = async (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('egg');

        values.configStartup = (await ref.current?.getStartupConfiguration()) ?? '';
        values.configFiles = (await ref.current?.getFilesConfiguration()) ?? '';

        updateEgg(egg.id, {
            ...values,
            dockerImages: toDockerImages(values.dockerImages),
            fileDenylist: parseDenylist(values.fileDenylistText),
            updateUrl: values.updateUrl || null,
        })
            .catch(error => {
                clearAndAddHttpError({ key: 'egg', error });
            })
            .then(() => setSubmitting(false));
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                name: egg.name,
                description: egg.description || '',
                startup: egg.startup,
                dockerImages: Object.keys(egg.dockerImages)
                    .map(key => `${egg.dockerImages[key]}|${key}`)
                    .join('\n'),
                configStop: egg.configStop || '',
                configStartup: JSON.stringify(egg.configStartup, null, '\t') || '',
                configFiles: JSON.stringify(egg.configFiles, null, '\t') || '',
                configFrom: egg.configFrom || null,
                copyScriptFrom: egg.copyScriptFrom || null,
                updateUrl: egg.updateUrl || '',
                features: egg.features || [],
                fileDenylistText: stringifyDenylist(egg.fileDenylist || []),
                forceOutgoingIp: egg.forceOutgoingIp,
                scriptIsPrivileged: egg.scriptIsPrivileged,
            }}
            validationSchema={object().shape({
                name: string().required().min(1).max(191),
                startup: string().required('Startup command is required.'),
                configStop: string().required('Stop command is required.'),
                dockerImages: string().required('At least one docker image is required.'),
            })}
        >
            {({ isSubmitting, isValid }) => (
                <Form>
                    <div css={tw`w-full flex flex-col gap-2 sm:flex-row sm:items-center mb-6`}>
                        <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                            <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>Egg Editor</h2>
                            <p css={tw`text-sm text-neutral-400`}>
                                A clean editing flow: identity, runtime, infrastructure, then process configuration.
                            </p>
                        </div>
                    </div>

                    <div css={tw`grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-6`}>
                        <div>
                            <EggIdentitySection inheritanceOptions={inheritanceOptions} />
                            <EggRuntimeSection />
                            <EggInfrastructureSection />
                            <EggProcessContainer ref={ref} />
                        </div>

                        <div css={tw`space-y-6`}>
                            <EggDetailsAside />

                            <AdminBox icon={faFireAlt} title={'Actions'}>
                                <SectionHint text={'Save is non-destructive. Delete is permanent.'} />
                                <div css={tw`flex flex-col gap-3`}>
                                    <Button type="submit" disabled={isSubmitting || !isValid}>
                                        Save Changes
                                    </Button>
                                    <EggExportButton className={''} />
                                    <EggDeleteButton eggId={egg.id} onDeleted={() => navigate('/admin/nests')} />
                                </div>
                            </AdminBox>
                        </div>
                    </div>

                    <div css={tw`rounded shadow-md px-4 xl:px-5 py-3 mb-16 mt-6`} style={{ backgroundColor: secondary }}>
                        <div css={tw`text-xs text-neutral-300`}>
                            Tip: Keep startup and process configuration in sync when changing game or engine versions.
                        </div>
                    </div>
                </Form>
            )}
        </Formik>
    );
}
