import { useState } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelopeOpenText } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { sendCustomEmail } from '@/api/routes/admin/email';
import Button from '@/elements/Button';
import Textarea from '@/elements/Textarea';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [to, setTo] = useState('');
    const [subject, setSubject] = useState('');
    const [html, setHtml] = useState('');
    const [text, setText] = useState('');

    const handleSend = () => {
        if (!to || !subject || !html) {
            addFlash({
                key: 'email:custom',
                type: 'danger',
                message: 'Please fill in all required fields',
            });
            return;
        }

        clearFlashes();
        setStatus('loading');

        sendCustomEmail({ to, subject, html, text: text || undefined })
            .then(response => {
                setStatus('success');
                if (response.success) {
                    addFlash({
                        key: 'email:custom',
                        type: 'success',
                        message: `Email sent successfully! Message ID: ${response.message_id}`,
                    });
                    // Clear form on success
                    setTo('');
                    setSubject('');
                    setHtml('');
                    setText('');
                } else {
                    addFlash({
                        key: 'email:custom',
                        type: 'danger',
                        message: response.error || 'Failed to send email',
                    });
                }
            })
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:custom', error });
            });
    };

    return (
        <AdminBox title={'Send Custom Email'} icon={faEnvelopeOpenText} byKey={'email:custom'} status={status}>
            <div>
                <Label>Recipient Email *</Label>
                <Input
                    placeholder={'recipient@example.com'}
                    id={'recipient'}
                    type={'email'}
                    name={'recipient'}
                    value={to}
                    onChange={e => setTo(e.target.value)}
                />
            </div>

            <div className={'mt-6'}>
                <Label>Subject *</Label>
                <Input
                    placeholder={'Email Subject'}
                    id={'subject'}
                    type={'text'}
                    name={'subject'}
                    value={subject}
                    onChange={e => setSubject(e.target.value)}
                />
            </div>

            <div className={'mt-6'}>
                <Label>HTML Content *</Label>
                <Textarea
                    placeholder={'<h1>Hello</h1><p>Your HTML email content here...</p>'}
                    id={'html'}
                    name={'html'}
                    value={html}
                    onChange={e => setHtml(e.target.value)}
                    rows={10}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    The HTML content of the email. You can use any valid HTML.
                </p>
            </div>

            <div className={'mt-6'}>
                <Label>Plain Text Content (Optional)</Label>
                <Textarea
                    placeholder={'Plain text version (auto-generated if not provided)'}
                    id={'text'}
                    name={'text'}
                    value={text}
                    onChange={e => setText(e.target.value)}
                    rows={6}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    Optional plain text version. If not provided, it will be auto-generated from the HTML.
                </p>
            </div>

            <div className={'mt-6'}>
                <Button onClick={handleSend} disabled={!to || !subject || !html}>
                    Send Email
                </Button>
            </div>
        </AdminBox>
    );
};
