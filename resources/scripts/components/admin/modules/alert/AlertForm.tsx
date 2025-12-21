import { Form, Formik } from 'formik';
import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import tw from 'twin.macro';
import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import { Button } from '@/elements/button';
import { Alert as AlertType, createAlert, updateAlert, getAlerts, CreateAlertData, UpdateAlertData } from '@/api/routes/admin/alerts';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/elements/FlashMessageRender';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { AlertType as AlertTypeEnum, AlertPosition } from '@/state/everest';
import Spinner from '@/elements/Spinner';

interface FormValues {
    title: string;
    content: string;
    type: AlertTypeEnum;
    position: AlertPosition;
    enabled: boolean;
    dismissible: boolean;
    link: string;
    link_text: string;
    priority: number;
    start_at: string;
    end_at: string;
}

export default () => {
    const navigate = useNavigate();
    const { id } = useParams();
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();
    const [loading, setLoading] = useState(!!id);
    const [initialValues, setInitialValues] = useState<FormValues>({
        title: '',
        content: '',
        type: 'info' as AlertTypeEnum,
        position: 'top-center' as AlertPosition,
        enabled: true,
        dismissible: false,
        link: '',
        link_text: '',
        priority: 0,
        start_at: '',
        end_at: '',
    });

    useEffect(() => {
        if (id) {
            getAlerts()
                .then(alerts => {
                    const alert = alerts.find(a => a.id === parseInt(id));
                    if (alert) {
                        setInitialValues({
                            title: alert.title || '',
                            content: alert.content,
                            type: alert.type,
                            position: alert.position,
                            enabled: alert.enabled,
                            dismissible: alert.dismissible,
                            link: alert.link || '',
                            link_text: alert.link_text || '',
                            priority: alert.priority,
                            start_at: alert.start_at ? alert.start_at.split('T')[0] : '',
                            end_at: alert.end_at ? alert.end_at.split('T')[0] : '',
                        });
                    }
                    setLoading(false);
                })
                .catch(error => {
                    clearAndAddHttpError({ key: 'alert:form', error });
                    setLoading(false);
                });
        }
    }, [id]);

    const submit = (values: FormValues) => {
        clearFlashes();

        const data: CreateAlertData | UpdateAlertData = {
            title: values.title || undefined,
            content: values.content,
            type: values.type,
            position: values.position,
            enabled: values.enabled,
            dismissible: values.dismissible,
            link: values.link || undefined,
            link_text: values.link_text || undefined,
            priority: values.priority,
            start_at: values.start_at || undefined,
            end_at: values.end_at || undefined,
        };

        const promise = id ? updateAlert(parseInt(id), data) : createAlert(data);

        promise
            .then(() => {
                addFlash({
                    type: 'success',
                    key: 'alert:form',
                    message: `Alert ${id ? 'updated' : 'created'} successfully.`,
                });
                setTimeout(() => navigate('/admin/alerts'), 1000);
            })
            .catch(error => {
                clearAndAddHttpError({
                    key: 'alert:form',
                    error: error,
                });
            });
    };

    if (loading) {
        return (
            <div css={tw`flex justify-center items-center py-12`}>
                <Spinner size={'large'} />
            </div>
        );
    }

    return (
        <Formik onSubmit={submit} initialValues={initialValues} enableReinitialize>
            {({ values, setFieldValue }) => (
                <Form>
                    <FlashMessageRender byKey={'alert:form'} className={'mb-4'} />
                    
                    <div css={tw`mb-6`}>
                        <Button.Text onClick={() => navigate('/admin/alerts')} css={tw`mb-4`}>
                            ← Back to Alerts
                        </Button.Text>
                        <h3 css={tw`text-2xl font-medium`}>{id ? 'Edit Alert' : 'Create Alert'}</h3>
                    </div>

                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
                        <AdminBox title={'Basic Information'}>
                            <div css={tw`space-y-4`}>
                                <div>
                                    <Label>Title (Optional)</Label>
                                    <Field id={'title'} name={'title'} type={'text'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Optional title for internal organization
                                    </p>
                                </div>
                                <div>
                                    <Label>Content *</Label>
                                    <Field id={'content'} name={'content'} type={'text'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        The message that will be displayed to users
                                    </p>
                                </div>
                            </div>
                        </AdminBox>

                        <AdminBox title={'Appearance'}>
                            <div css={tw`space-y-4`}>
                                <div>
                                    <Label>Type *</Label>
                                    <Select
                                        onChange={e => setFieldValue('type', e.currentTarget.value)}
                                        value={values.type}
                                    >
                                        <option value={'success'}>Success (Green)</option>
                                        <option value={'info'}>Info (Blue)</option>
                                        <option value={'warning'}>Warning (Yellow)</option>
                                        <option value={'danger'}>Danger (Red)</option>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Position *</Label>
                                    <Select
                                        onChange={e => setFieldValue('position', e.currentTarget.value)}
                                        value={values.position}
                                    >
                                        <option value={'top-center'}>Top Center</option>
                                        <option value={'bottom-right'}>Bottom Right</option>
                                        <option value={'bottom-left'}>Bottom Left</option>
                                        <option value={'center'}>Center (Dialog)</option>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Priority</Label>
                                    <Field id={'priority'} name={'priority'} type={'number'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Higher priority alerts appear first (default: 0)
                                    </p>
                                </div>
                            </div>
                        </AdminBox>

                        <AdminBox title={'Settings'}>
                            <div css={tw`space-y-4`}>
                                <div className={'flex items-center'}>
                                    <Field
                                        id={'enabled'}
                                        name={'enabled'}
                                        type={'checkbox'}
                                        checked={values.enabled}
                                    />
                                    <Label className={'ml-2 mb-0'}>Enabled</Label>
                                </div>
                                <div className={'flex items-center'}>
                                    <Field
                                        id={'dismissible'}
                                        name={'dismissible'}
                                        type={'checkbox'}
                                        checked={values.dismissible}
                                    />
                                    <Label className={'ml-2 mb-0'}>Dismissible by users</Label>
                                </div>
                            </div>
                        </AdminBox>

                        <AdminBox title={'Link (Optional)'}>
                            <div css={tw`space-y-4`}>
                                <div>
                                    <Label>Link URL</Label>
                                    <Field id={'link'} name={'link'} type={'text'} />
                                </div>
                                <div>
                                    <Label>Link Text</Label>
                                    <Field id={'link_text'} name={'link_text'} type={'text'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Text to display for the link (defaults to URL)
                                    </p>
                                </div>
                            </div>
                        </AdminBox>

                        <AdminBox title={'Schedule (Optional)'} className={'md:col-span-2'}>
                            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                <div>
                                    <Label>Start Date</Label>
                                    <Field id={'start_at'} name={'start_at'} type={'date'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Alert will only show after this date
                                    </p>
                                </div>
                                <div>
                                    <Label>End Date</Label>
                                    <Field id={'end_at'} name={'end_at'} type={'date'} />
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Alert will only show until this date
                                    </p>
                                </div>
                            </div>
                        </AdminBox>
                    </div>

                    <div css={tw`w-full flex flex-row items-center mt-6 gap-4`}>
                        <Button.Text onClick={() => navigate('/admin/alerts')}>Cancel</Button.Text>
                        <div css={tw`flex ml-auto`}>
                            <Button type="submit">{id ? 'Update Alert' : 'Create Alert'}</Button>
                        </div>
                    </div>
                </Form>
            )}
        </Formik>
    );
};
