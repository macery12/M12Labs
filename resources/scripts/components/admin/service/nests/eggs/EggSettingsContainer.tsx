import { LanguageDescription } from '@codemirror/language';
import { json } from '@codemirror/lang-json';
import { faDocker } from '@fortawesome/free-brands-svg-icons';
import { faEgg, faFireAlt, faMicrochip, faTerminal } from '@fortawesome/free-solid-svg-icons';
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
import { Button } from '@/elements/button';
import { Editor } from '@/elements/editor';
import Field from '@/elements/Field';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import Checkbox from '@/elements/inputs/Checkbox';
import DockerImageManager from '@admin/service/nests/eggs/DockerImageManager';

const EGG_FEATURES = [
    { key: 'eula', label: 'Show EULA popup' },
    { key: 'fastdl', label: 'Enable FastDL support' },
] as const;

function SelectEggField({
    id,
    label,
    value,
    options,
    onChange,
    includeCurrent,
}: {
    id: string;
    label: string;
    value: number | null;
    options: EggType[];
    onChange: (value: number | null) => void;
    includeCurrent?: string;
}) {
    return (
        <div css={tw`mb-4`}>
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                value={value ?? ''}
                onChange={e => onChange(e.currentTarget.value ? Number(e.currentTarget.value) : null)}
                css={tw`w-full mt-2 rounded px-3 py-2 bg-neutral-900 border border-neutral-700 text-neutral-100`}
            >
                <option value={''}>None</option>
                {includeCurrent && (
                    <option value={''} disabled>
                        Current: {includeCurrent}
                    </option>
                )}
                {options.map(opt => (
                    <option key={opt.id} value={opt.id}>
                        {opt.name} (#{opt.id})
                    </option>
                ))}
            </select>
        </div>
    );
}

export function EggInformationContainer({ inheritanceOptions }: { inheritanceOptions: EggType[] }) {
    const { isSubmitting, values, setFieldValue } = useFormikContext<Values>();

    return (
        <AdminBox icon={faEgg} title={'Egg Information'} css={tw`relative`}>
            <SpinnerOverlay visible={isSubmitting} />

            <Field id={'name'} name={'name'} label={'Name'} type={'text'} css={tw`mb-6`} />
            <Field id={'description'} name={'description'} label={'Description'} type={'text'} css={tw`mb-6`} />
            <Field id={'updateUrl'} name={'updateUrl'} label={'Update URL'} type={'text'} css={tw`mb-2`} />

            <SelectEggField
                id={'configFrom'}
                label={'Inherit Configuration From'}
                value={values.configFrom}
                options={inheritanceOptions}
                onChange={value => setFieldValue('configFrom', value)}
            />

            <SelectEggField
                id={'copyScriptFrom'}
                label={'Inherit Script From'}
                value={values.copyScriptFrom}
                options={inheritanceOptions}
                onChange={value => setFieldValue('copyScriptFrom', value)}
            />
        </AdminBox>
    );
}

function EggDetailsContainer() {
    const { data: egg } = useEggFromRoute();

    if (!egg) {
        return null;
    }

    return (
        <AdminBox icon={faEgg} title={'Egg Details'} css={tw`relative`}>
            <div css={tw`mb-6`}>
                <Label>UUID</Label>
                <Input id={'uuid'} name={'uuid'} type={'text'} value={egg.uuid} readOnly />
            </div>

            <div css={tw`mb-2`}>
                <Label>Author</Label>
                <Input id={'author'} name={'author'} type={'text'} value={egg.author} readOnly />
            </div>

            <div css={tw`mt-6`}>
                <Label>Created</Label>
                <Input type={'text'} value={egg.createdAt.toLocaleString()} readOnly />
            </div>
        </AdminBox>
    );
}

export function EggStartupContainer({ className }: { className?: string }) {
    const { isSubmitting } = useFormikContext();

    return (
        <AdminBox icon={faTerminal} title={'Startup Command'} css={tw`relative`} className={className}>
            <SpinnerOverlay visible={isSubmitting} />
            <Field id={'startup'} name={'startup'} label={'Startup Command'} type={'text'} css={tw`mb-1`} />
        </AdminBox>
    );
}

export function EggImageContainer() {
    const { isSubmitting } = useFormikContext();

    return (
        <AdminBox icon={faDocker} title={'Docker'} css={tw`relative`}>
            <SpinnerOverlay visible={isSubmitting} />
            <DockerImageManager name={'dockerImages'} />
        </AdminBox>
    );
}

export function EggLifecycleContainer() {
    const { isSubmitting } = useFormikContext<Values>();

    return (
        <AdminBox icon={faFireAlt} title={'Lifecycle'} css={tw`relative`}>
            <SpinnerOverlay visible={isSubmitting} />

            <Field id={'configStop'} name={'configStop'} label={'Stop Command'} type={'text'} css={tw`mb-4`} />

            <div css={tw`flex items-center mb-3`}>
                <Field
                    type="checkbox"
                    // @ts-expect-error checkbox rendering
                    as={Checkbox}
                    id={'forceOutgoingIp'}
                    name={'forceOutgoingIp'}
                />
                <div css={tw`ml-3`}>
                    <Label>Force Outgoing IP</Label>
                </div>
            </div>

            <div css={tw`flex items-center mb-2`}>
                <Field
                    type="checkbox"
                    // @ts-expect-error checkbox rendering
                    as={Checkbox}
                    id={'scriptIsPrivileged'}
                    name={'scriptIsPrivileged'}
                />
                <div css={tw`ml-3`}>
                    <Label>Install Script Runs as Root</Label>
                </div>
            </div>
        </AdminBox>
    );
}

function EggFeaturesContainer() {
    const { values, setFieldValue } = useFormikContext<Values>();

    const isEnabled = (key: string) => values.features.includes(key);

    const toggle = (key: string) => {
        const next = isEnabled(key) ? values.features.filter(v => v !== key) : [...values.features, key];
        setFieldValue('features', next);
    };

    return (
        <AdminBox icon={faEgg} title={'Features'}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                {EGG_FEATURES.map(feature => (
                    <button
                        key={feature.key}
                        type={'button'}
                        onClick={() => toggle(feature.key)}
                        css={tw`text-left rounded border px-3 py-3 border-neutral-700 bg-neutral-900 hover:border-neutral-500`}
                    >
                        <div css={tw`font-medium text-neutral-100`}>{feature.label}</div>
                        <div css={tw`text-xs text-neutral-400 mt-1`}>{isEnabled(feature.key) ? 'Enabled' : 'Disabled'}</div>
                    </button>
                ))}
            </div>
        </AdminBox>
    );
}

function EggDenylistContainer() {
    const { values, setFieldValue } = useFormikContext<Values>();
    const [entry, setEntry] = useState('');

    const addItem = () => {
        const next = entry.trim();
        if (next.length < 1 || values.fileDenylist.includes(next)) {
            return;
        }

        setFieldValue('fileDenylist', [...values.fileDenylist, next]);
        setEntry('');
    };

    return (
        <AdminBox icon={faEgg} title={'Access Control'}>
            <Label>File Denylist</Label>

            <div css={tw`flex gap-2 mt-2`}>
                <Input
                    type={'text'}
                    value={entry}
                    placeholder={'forbidden-file.jar'}
                    onChange={e => setEntry(e.currentTarget.value)}
                    onKeyDown={e => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addItem();
                        }
                    }}
                />
                <Button type={'button'} onClick={addItem}>
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
    );
}

interface EggProcessContainerProps {
    className?: string;
}

export interface EggProcessContainerRef {
    getStartupConfiguration: () => Promise<string | null>;
    getFilesConfiguration: () => Promise<string | null>;
}

export const EggProcessContainer = forwardRef<any, EggProcessContainerProps>(function EggProcessContainer(
    { className },
    ref,
) {
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
        <AdminBox icon={faMicrochip} title={'Process Configuration'} css={tw`relative`} className={className}>
            <SpinnerOverlay visible={isSubmitting} />

            <div css={tw`mb-5`}>
                <Label>Startup Configuration</Label>
                <Editor
                    childClassName={tw`h-32 rounded`}
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
                    childClassName={tw`h-48 rounded`}
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

        const dockerImages: Record<string, string> = {};
        for (const line of values.dockerImages.split('\n')) {
            const parts = line.trim().split('|');
            const image = parts[0]?.trim();
            if (!image) {
                continue;
            }
            const alias = (parts[1] || image).trim();
            dockerImages[alias] = image;
        }

        updateEgg(egg.id, {
            ...values,
            dockerImages,
            forceOutgoingIp: values.forceOutgoingIp,
            fileDenylist: values.fileDenylist,
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
                fileDenylist: egg.fileDenylist || [],
                forceOutgoingIp: egg.forceOutgoingIp,
                scriptIsPrivileged: egg.scriptIsPrivileged,
            }}
            validationSchema={object().shape({
                name: string().required().min(1).max(191),
                startup: string().required(),
                configStop: string().required(),
            })}
        >
            {({ isSubmitting, isValid }) => (
                <Form>
                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mb-6`}>
                        <EggInformationContainer inheritanceOptions={inheritanceOptions} />
                        <EggDetailsContainer />
                    </div>

                    <EggStartupContainer css={tw`mb-6`} />

                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mb-6`}>
                        <EggImageContainer />
                        <EggLifecycleContainer />
                    </div>

                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mb-6`}>
                        <EggFeaturesContainer />
                        <EggDenylistContainer />
                    </div>

                    <EggProcessContainer ref={ref} css={tw`mb-6`} />

                    <div css={tw`rounded shadow-md px-4 xl:px-5 py-4 mb-16`} style={{ backgroundColor: secondary }}>
                        <div css={tw`flex flex-row flex-wrap gap-3`}>
                            <EggDeleteButton eggId={egg.id} onDeleted={() => navigate('/admin/nests')} />
                            <EggExportButton className={'ml-auto'} />
                            <Button type="submit" disabled={isSubmitting || !isValid}>
                                Save Changes
                            </Button>
                        </div>
                    </div>
                </Form>
            )}
        </Formik>
    );
}
