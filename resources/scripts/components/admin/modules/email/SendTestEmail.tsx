import { useState } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faPaperPlane } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { sendTestEmail } from '@/api/routes/admin/email';
import Button from '@/elements/Button';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [email, setEmail] = useState('');

    const handleSendTest = () => {
        if (!email) {
            addFlash({
                key: 'email:test',
                type: 'danger',
                message: 'Please enter an email address',
            });
            return;
        }

        clearFlashes();
        setStatus('loading');

        sendTestEmail({ to: email })
            .then(response => {
                setStatus('success');
                if (response.success) {
                    addFlash({
                        key: 'email:test',
                        type: 'success',
                        message: `Test email sent successfully! Message ID: ${response.message_id}`,
                    });
                } else {
                    addFlash({
                        key: 'email:test',
                        type: 'danger',
                        message: response.error || 'Failed to send test email',
                    });
                }
            })
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:test', error });
            });
    };

    return (
        <AdminBox title={'Send Test Email'} icon={faPaperPlane} byKey={'email:test'} status={status}>
            <div>
                <Label>Recipient Email</Label>
                <Input
                    placeholder={'test@example.com'}
                    id={'test_email'}
                    type={'email'}
                    name={'test_email'}
                    value={email}
                    onChange={e => setEmail(e.target.value)}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    Send a test email to verify your Resend configuration is working.
                </p>
            </div>

            <div className={'mt-6'}>
                <Button onClick={handleSendTest} disabled={!email}>
                    Send Test Email
                </Button>
            </div>
        </AdminBox>
    );
};
