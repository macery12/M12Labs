import type { FormikHelpers } from 'formik';
import { Form, Formik } from 'formik';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import tw from 'twin.macro';
import { array, object, string } from 'yup';

import createEgg from '@/api/routes/admin/eggs/createEgg';
import type { Egg as EggType } from '@/api/routes/admin/egg';
import { searchEggs } from '@/api/routes/admin/egg';
import AdminContentBlock from '@/elements/AdminContentBlock';
import type { EggProcessContainerRef } from '@admin/service/nests/eggs/EggSettingsContainer';
import {
    EggImageContainer,
    EggLifecycleContainer,
    EggProcessContainer,
    EggStartupContainer,
} from '@admin/service/nests/eggs/EggSettingsContainer';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import Field from '@/elements/Field';
import Label from '@/elements/Label';

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

function EggSelectionContainer({
    inheritanceOptions,
}: {
    inheritanceOptions: EggType[];
}) {
    return (
        <AdminBox title={'Egg Selection & Inheritance'} css={tw`mb-6`}>
            <Field id={'name'} name={'name'} label={'Name'} type={'text'} css={tw`mb-6`} />
            <Field id={'description'} name={'description'} label={'Description'} type={'text'} css={tw`mb-6`} />

            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
                <div>
                    <Label htmlFor={'configFrom'}>Inherit Configuration From</Label>
                    <Field as={'select'} id={'configFrom'} name={'configFrom'} css={tw`w-full mt-2`}>
                        <option value={''}>None</option>
                        {inheritanceOptions.map(opt => (
                            <option key={opt.id} value={opt.id}>
                                {opt.name} (#{opt.id})
                            </option>
                        ))}
                    </Field>
                </div>

                <div>
                    <Label htmlFor={'copyScriptFrom'}>Inherit Script From</Label>
                    <Field as={'select'} id={'copyScriptFrom'} name={'copyScriptFrom'} css={tw`w-full mt-2`}>
                        <option value={''}>None</option>
                        {inheritanceOptions.map(opt => (
                            <option key={opt.id} value={opt.id}>
                                {opt.name} (#{opt.id})
                            </option>
                        ))}
                    </Field>
                </div>
            </div>
        </AdminBox>
    );
}

export default function NewEggContainer() {
    const navigate = useNavigate();
    const params = useParams<{ nestId: string }>();

    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const ref = useRef<EggProcessContainerRef>();
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
        values.dockerImages.split('\n').forEach(v => {
            const parts = v.trim().split('|');
            const image = parts[0] || '';
            if (image.length < 1) {
                return;
            }

            const alias = parts[1] || image;
            dockerImages[alias] = image;
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
            <div css={tw`w-full flex flex-col gap-2 sm:flex-row sm:items-center mb-8`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>New Egg</h2>
                    <p css={tw`hidden md:block text-base text-neutral-400`}>Create an egg with a simple, single-page form.</p>
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
                        <EggSelectionContainer inheritanceOptions={inheritanceOptions} />

                        <EggStartupContainer css={tw`mb-6`} />

                        <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mb-6`}>
                            <EggImageContainer />
                            <EggLifecycleContainer />
                        </div>

                        <EggProcessContainer ref={ref} css={tw`mb-6`} />

                        <div css={tw`bg-zinc-800 rounded shadow-md py-2 px-6 mb-16`}>
                            <div css={tw`flex flex-row`}>
                                <Button type="submit" css={tw`ml-auto`} disabled={isSubmitting || !isValid}>
                                    Create Egg
                                </Button>
                            </div>
                        </div>
                    </Form>
                )}
            </Formik>
        </AdminContentBlock>
    );
}
