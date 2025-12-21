import { Form, Formik } from 'formik';
import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import tw from 'twin.macro';
import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import { Button } from '@/elements/button';
import { Alert as AlertType, createAlert, updateAlert, getAlerts, CreateAlertData, UpdateAlertData, AlertScope, ButtonPosition, UserTargeting, searchUsers, AlertUser } from '@/api/routes/admin/alerts';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/elements/FlashMessageRender';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import { AlertType as AlertTypeEnum, AlertPosition } from '@/state/everest';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes, faSearch } from '@fortawesome/free-solid-svg-icons';

interface FormValues {
    title: string;
    content: string;
    type: AlertTypeEnum;
    position: AlertPosition;
    scope: AlertScope;
    user_targeting: UserTargeting;
    enabled: boolean;
    dismissible: boolean;
    show_button: boolean;
    button_text: string;
    button_position: ButtonPosition;
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
    const [selectedUsers, setSelectedUsers] = useState<AlertUser[]>([]);
    const [userSearchQuery, setUserSearchQuery] = useState('');
    const [userSearchResults, setUserSearchResults] = useState<AlertUser[]>([]);
    const [searchingUsers, setSearchingUsers] = useState(false);
    const [initialValues, setInitialValues] = useState<FormValues>({
        title: '',
        content: '',
        type: 'info' as AlertTypeEnum,
        position: 'top-center' as AlertPosition,
        scope: 'global' as AlertScope,
        user_targeting: 'all' as UserTargeting,
        enabled: true,
        dismissible: false,
        show_button: false,
        button_text: 'Show Alert',
        button_position: 'bottom-right' as ButtonPosition,
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
                            scope: alert.scope,
                            user_targeting: alert.user_targeting,
                            enabled: alert.enabled,
                            dismissible: alert.dismissible,
                            show_button: alert.show_button,
                            button_text: alert.button_text || 'Show Alert',
                            button_position: alert.button_position,
                            link: alert.link || '',
                            link_text: alert.link_text || '',
                            priority: alert.priority,
                            start_at: alert.start_at ? alert.start_at.split('T')[0] : '',
                            end_at: alert.end_at ? alert.end_at.split('T')[0] : '',
                        });
                        
                        if (alert.users) {
                            setSelectedUsers(alert.users);
                        }
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
            scope: values.scope,
            user_targeting: values.user_targeting,
            user_ids: values.user_targeting === 'specific' ? selectedUsers.map(u => u.id) : undefined,
            enabled: values.enabled,
            dismissible: values.dismissible,
            show_button: values.show_button,
            button_text: values.button_text || undefined,
            button_position: values.button_position,
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
                                        <option value={'top-center'}>Top Center Banner</option>
                                        <option value={'slide-out'}>Slide-out Card (Right Side)</option>
                                        <option value={'center'}>Center (Popup Dialog)</option>
                                    </Select>
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Choose where and how the alert appears
                                    </p>
                                </div>
                                <div>
                                    <Label>Scope *</Label>
                                    <Select
                                        onChange={e => setFieldValue('scope', e.currentTarget.value)}
                                        value={values.scope}
                                    >
                                        <option value={'global'}>Global (All Pages)</option>
                                        <option value={'dashboard'}>Dashboard Only</option>
                                        <option value={'server'}>Server Pages Only</option>
                                        <option value={'billing'}>Billing Pages Only</option>
                                        <option value={'account'}>Account Pages Only</option>
                                        <option value={'admin'}>Admin Panel Only</option>
                                    </Select>
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Choose where this alert should be displayed
                                    </p>
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
                                <div className={'flex items-center'}>
                                    <Field
                                        id={'show_button'}
                                        name={'show_button'}
                                        type={'checkbox'}
                                        checked={values.show_button}
                                    />
                                    <Label className={'ml-2 mb-0'}>Show reopen button</Label>
                                </div>
                                <p className={'text-gray-400 text-xs'}>
                                    If enabled, a floating button will appear to reopen dismissed alerts
                                </p>
                                {values.show_button && (
                                    <>
                                        <div>
                                            <Label>Button Text</Label>
                                            <Field id={'button_text'} name={'button_text'} type={'text'} />
                                        </div>
                                        <div>
                                            <Label>Button Position</Label>
                                            <Select
                                                onChange={e => setFieldValue('button_position', e.currentTarget.value)}
                                                value={values.button_position}
                                            >
                                                <option value={'bottom-right'}>Bottom Right</option>
                                                <option value={'bottom-left'}>Bottom Left</option>
                                                <option value={'top-right'}>Top Right</option>
                                                <option value={'top-left'}>Top Left</option>
                                            </Select>
                                        </div>
                                    </>
                                )}
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

                        <AdminBox title={'User Targeting'} className={'md:col-span-2'}>
                            <div css={tw`space-y-4`}>
                                <div>
                                    <Label>Target Users *</Label>
                                    <Select
                                        onChange={e => setFieldValue('user_targeting', e.currentTarget.value)}
                                        value={values.user_targeting}
                                    >
                                        <option value={'all'}>All Users</option>
                                        <option value={'specific'}>Specific Users Only</option>
                                    </Select>
                                    <p className={'text-gray-400 text-xs mt-1'}>
                                        Choose whether to show this alert to all users or specific users
                                    </p>
                                </div>

                                {values.user_targeting === 'specific' && (
                                    <div>
                                        <Label>Select Users</Label>
                                        <div css={tw`relative`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <FontAwesomeIcon 
                                                    icon={faSearch} 
                                                    css={tw`absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400`}
                                                />
                                                <input
                                                    type="text"
                                                    value={userSearchQuery}
                                                    onChange={e => setUserSearchQuery(e.target.value)}
                                                    placeholder="Search by email or username..."
                                                    css={tw`w-full pl-10 pr-4 py-2 bg-neutral-800 border border-neutral-700 rounded text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500`}
                                                />
                                            </div>
                                            
                                            {userSearchResults.length > 0 && (
                                                <div css={tw`absolute z-10 w-full mt-1 bg-neutral-800 border border-neutral-700 rounded shadow-lg max-h-60 overflow-y-auto`}>
                                                    {userSearchResults.map(user => (
                                                        <div
                                                            key={user.id}
                                                            onClick={() => addUser(user)}
                                                            css={tw`px-4 py-2 hover:bg-neutral-700 cursor-pointer text-sm`}
                                                        >
                                                            <div css={tw`font-medium`}>{user.email}</div>
                                                            <div css={tw`text-xs text-gray-400`}>@{user.username}</div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {selectedUsers.length > 0 && (
                                            <div css={tw`mt-3 flex flex-wrap gap-2`}>
                                                {selectedUsers.map(user => (
                                                    <div
                                                        key={user.id}
                                                        css={tw`flex items-center gap-2 bg-neutral-700 px-3 py-1 rounded text-sm`}
                                                    >
                                                        <span>{user.email}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeUser(user.id)}
                                                            css={tw`text-gray-400 hover:text-red-400`}
                                                        >
                                                            <FontAwesomeIcon icon={faTimes} />
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        <p className={'text-gray-400 text-xs mt-2'}>
                                            {selectedUsers.length} user(s) selected
                                        </p>
                                    </div>
                                )}
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
