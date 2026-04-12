import { useState } from 'react';
import Label from '@/elements/Label';
import Select from '@/elements/Select';
import AdminBox from '@/elements/AdminBox';
import Input from '@/elements/Input';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import useStatus from '@/plugins/useStatus';
import { faShieldHalved } from '@fortawesome/free-solid-svg-icons';
import { toggleModule } from '@/api/routes/admin/auth/module';
import { updateJGuardSettings } from '@/api/routes/admin/auth/jguard';
import { Alert } from '@/elements/alert';
import { Dialog } from '@/elements/dialog';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const jguard = useStoreState(state => state.everest.data!.auth.modules.jguard);
    const [confirmDisable, setConfirmDisable] = useState(false);

    const updateSetting = async (values: { approval_mode?: string; delay?: number }) => {
        clearFlashes();
        setStatus('loading');
        updateJGuardSettings(values)
            .then(() => setStatus('success'))
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'auth:jguard:settings', error });
            });
    };

    const doDisable = () => {
        toggleModule('disable', 'jguard')
            .then(() => {
                // @ts-expect-error navigation redirect
                window.location = '/admin/auth';
            })
            .catch(error => clearAndAddHttpError({ key: 'auth:jguard:settings', error }));
    };

    return (
        <div className={'space-y-6'}>
            <Dialog.Confirm
                open={confirmDisable}
                title={'Disable jGuard'}
                onConfirmed={() => doDisable()}
                onClose={() => setConfirmDisable(false)}
            >
                Are you sure you want to disable jGuard? Pending accounts will not be automatically approved.
            </Dialog.Confirm>

            <AdminBox title={'jGuard Settings'} icon={faShieldHalved} byKey={'auth:jguard:settings'} status={status}>
                <div>
                    <Label>Approval Mode</Label>
                    <Select
                        id={'approval_mode'}
                        name={'approval_mode'}
                        defaultValue={jguard.approval_mode}
                        onChange={e => updateSetting({ approval_mode: e.target.value })}
                        autoComplete={'off'}
                    >
                        <option value={'manual'}>Manual — Admin must approve each account</option>
                        <option value={'delayed'}>Delayed — Accounts activate after a set time</option>
                        <option value={'immediate'}>Immediate — Accounts activate instantly (gating disabled)</option>
                    </Select>
                    <p className={'mt-1 text-xs text-gray-400'}>
                        Controls how new registrations are processed when jGuard is enabled.
                    </p>
                </div>

                {jguard.approval_mode === 'delayed' && (
                    <div className={'mt-6'}>
                        <Label>Activation Delay (minutes)</Label>
                        <Input
                            autoComplete={'off'}
                            id={'delay'}
                            type={'number'}
                            name={'delay'}
                            defaultValue={jguard.delay}
                            onBlur={e => updateSetting({ delay: parseInt(e.target.value) })}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            How many minutes a new account must wait before it is automatically activated.
                        </p>
                    </div>
                )}

                <div className={'mt-6'}>
                    <button
                        className={
                            'text-xs text-red-400 hover:text-red-300 transition-colors underline underline-offset-2'
                        }
                        onClick={() => setConfirmDisable(true)}
                    >
                        Disable jGuard module
                    </button>
                </div>
            </AdminBox>

            {jguard.approval_mode === 'manual' && (
                <Alert type={'info'}>
                    <span className={'text-xs'}>
                        Manual approval mode is active. New registrations will be held until you approve them from
                        the <strong>Pending Accounts</strong> tab.
                    </span>
                </Alert>
            )}
        </div>
    );
};
