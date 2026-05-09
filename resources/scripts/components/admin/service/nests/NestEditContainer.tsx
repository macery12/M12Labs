import type { Action, Actions } from 'easy-peasy';
import { action, createContextStore, useStoreActions } from 'easy-peasy';
import type { FormikHelpers } from 'formik';
import { Form, Formik } from 'formik';
import { useEffect, useState } from 'react';
import { NavLink, useNavigate, useParams } from 'react-router-dom';
import tw from 'twin.macro';
import { object, string } from 'yup';

import ImportEggButton from '@admin/service/nests/ImportEggButton';
import AdminContentBlock from '@/elements/AdminContentBlock';
import Spinner from '@/elements/Spinner';
import FlashMessageRender from '@/elements/FlashMessageRender';
import type { Nest } from '@/api/routes/admin/nests/getNests';
import getNest from '@/api/routes/admin/nests/getNest';
import updateNest from '@/api/routes/admin/nests/updateNest';
import { Button } from '@/elements/button';
import { Size } from '@/elements/button/types';
import Field from '@/elements/Field';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import AdminBox from '@/elements/AdminBox';
import CopyOnClick from '@/elements/CopyOnClick';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import NestDeleteButton from '@admin/service/nests/NestDeleteButton';
import NestEggTable from '@admin/service/nests/NestEggTable';
import type { ApplicationStore } from '@/state';

interface ctx {
    nest: Nest | undefined;
    setNest: Action<ctx, Nest | undefined>;

    selectedEggs: number[];

    setSelectedEggs: Action<ctx, number[]>;
    appendSelectedEggs: Action<ctx, number>;
    removeSelectedEggs: Action<ctx, number>;
}

export const Context = createContextStore<ctx>({
    nest: undefined,

    setNest: action((state, payload) => {
        state.nest = payload;
    }),

    selectedEggs: [],

    setSelectedEggs: action((state, payload) => {
        state.selectedEggs = payload;
    }),

    appendSelectedEggs: action((state, payload) => {
        state.selectedEggs = state.selectedEggs.filter(id => id !== payload).concat(payload);
    }),

    removeSelectedEggs: action((state, payload) => {
        state.selectedEggs = state.selectedEggs.filter(id => id !== payload);
    }),
});

interface Values {
    name: string;
    description: string;
}

const NestSettings = () => {
    const navigate = useNavigate();
    const { clearFlashes, clearAndAddHttpError } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const nest = Context.useStoreState(state => state.nest);
    const setNest = Context.useStoreActions(actions => actions.setNest);

    if (!nest) {
        return null;
    }

    const submit = ({ name, description }: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('nest');

        updateNest(nest.id, name, description, nest.author)
            .then(() => setNest({ ...nest, name, description }))
            .catch(error => {
                clearAndAddHttpError({ key: 'nest', error });
            })
            .then(() => setSubmitting(false));
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                name: nest.name,
                description: nest.description || '',
            }}
            validationSchema={object().shape({
                name: string().required().min(1),
                description: string().max(255, ''),
            })}
        >
            {({ isSubmitting, isValid }) => (
                <AdminBox title={'Nest Settings'} css={tw`relative`}>
                    <SpinnerOverlay visible={isSubmitting} />

                    <Form>
                        <Field id={'name'} name={'name'} label={'Name'} type={'text'} css={tw`mb-6`} />
                        <Field id={'description'} name={'description'} label={'Description'} type={'text'} css={tw`mb-6`} />

                        <div css={tw`flex items-center gap-3`}>
                            <NestDeleteButton nestId={nest.id} onDeleted={() => navigate('/admin/nests')} />
                            <Button type="submit" className={'ml-auto'} disabled={isSubmitting || !isValid}>
                                Save Changes
                            </Button>
                        </div>
                    </Form>
                </AdminBox>
            )}
        </Formik>
    );
};

const NestSidebar = () => {
    const nest = Context.useStoreState(state => state.nest);

    if (!nest) {
        return null;
    }

    return (
        <div css={tw`sticky top-6 space-y-4`}>
            <AdminBox title={'Nest Details'}>
                <div css={tw`space-y-4`}>
                    <div>
                        <Label>ID</Label>
                        <CopyOnClick text={nest.id.toString()}>
                            <Input type={'text'} value={nest.id} readOnly />
                        </CopyOnClick>
                    </div>

                    <div>
                        <Label>UUID</Label>
                        <CopyOnClick text={nest.uuid}>
                            <Input type={'text'} value={nest.uuid} readOnly />
                        </CopyOnClick>
                    </div>

                    <div>
                        <Label>Author</Label>
                        <CopyOnClick text={nest.author}>
                            <Input type={'text'} value={nest.author} readOnly />
                        </CopyOnClick>
                    </div>
                </div>
            </AdminBox>

            <AdminBox title={'Stats'}>
                <div css={tw`text-sm text-neutral-300`}>{nest.relations.eggs?.length || 0} eggs in this nest</div>
            </AdminBox>
        </div>
    );
};

const NestEditContainer = () => {
    const params = useParams<'nestId'>();

    const { clearFlashes, clearAndAddHttpError } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState<'eggs' | 'settings'>('eggs');

    const nest = Context.useStoreState(state => state.nest);
    const setNest = Context.useStoreActions(actions => actions.setNest);

    useEffect(() => {
        clearFlashes('nest');

        getNest(Number(params.nestId), ['eggs'])
            .then(current => setNest(current))
            .catch(error => {
                clearAndAddHttpError({ key: 'nest', error });
            })
            .then(() => setLoading(false));
    }, []);

    if (loading || nest === undefined) {
        return (
            <AdminContentBlock>
                <FlashMessageRender byKey={'nest'} css={tw`mb-4`} />

                <div css={tw`w-full flex flex-col items-center justify-center`} style={{ height: '24rem' }}>
                    <Spinner size={'base'} />
                </div>
            </AdminContentBlock>
        );
    }

    return (
        <AdminContentBlock title={'Nests - ' + nest.name}>
            <div css={tw`text-sm text-neutral-400 mb-3`}>
                <NavLink to={'/admin/nests'} className={'hover:text-neutral-200'}>
                    Nests
                </NavLink>
                {' / '}
                <span css={tw`text-neutral-200`}>{nest.name}</span>
            </div>

            <div css={tw`w-full flex flex-col gap-2 sm:flex-row sm:items-center mb-8`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>{nest.name}</h2>
                    <p css={tw`text-base text-neutral-400`}>{nest.description || 'No description'}</p>
                </div>

                <div css={tw`flex flex-row ml-auto pl-4`}>
                    <ImportEggButton className={'mr-4'} />

                    <NavLink to={`/admin/nests/${params.nestId}/new`}>
                        <Button type={'button'} size={Size.Large} css={tw`h-10 px-4 py-0 whitespace-nowrap`}>
                            New Egg
                        </Button>
                    </NavLink>
                </div>
            </div>

            <FlashMessageRender byKey={'nest'} css={tw`mb-4`} />

            <div css={tw`grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_280px] gap-6 mb-8`}>
                <div>
                    <div css={tw`flex gap-2 mb-4`}>
                        <Button.Text type={'button'} onClick={() => setActiveTab('eggs')}>
                            Eggs
                        </Button.Text>
                        <Button.Text type={'button'} onClick={() => setActiveTab('settings')}>
                            Settings
                        </Button.Text>
                    </div>

                    {activeTab === 'eggs' ? <NestEggTable /> : <NestSettings />}
                </div>

                <NestSidebar />
            </div>
        </AdminContentBlock>
    );
};

export default () => {
    return (
        <Context.Provider>
            <NestEditContainer />
        </Context.Provider>
    );
};
