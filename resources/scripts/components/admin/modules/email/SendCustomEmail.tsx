import { useState } from 'react';
import Label from '@/elements/Label';
import Input, { Textarea } from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelopeOpenText } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { sendCustomEmail } from '@/api/routes/admin/email';
import { Button } from '@/elements/button';
import Modal from '@/elements/Modal';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [to, setTo] = useState('');
    const [subject, setSubject] = useState('');
    const [html, setHtml] = useState('');
    const [text, setText] = useState('');
    const [isOpen, setIsOpen] = useState(false);

    const handleSend = () => {
        if (!to || !subject || !html) {
            addFlash({
                key: 'email:custom',
                type: 'danger',
                message: 'Please fill in all required fields',
            });
            return;
        }

        clearFlashes('email:custom');
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
                    setIsOpen(false);
                } else {
                    const errorMessage =
                        typeof response.error === 'string'
                            ? response.error
                            : response.error?.message || 'Failed to send email';

                    addFlash({
                        key: 'email:custom',
                        type: 'danger',
                        message: errorMessage,
                    });
                }
            })
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:custom', error });
            });
    };

    return (
        <>
            <AdminBox title={'Send Custom Email'} icon={faEnvelopeOpenText} byKey={'email:custom'} status={status}>
                <div className={'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between'}>
                    <div>
                        <p className={'text-sm text-neutral-300'}>
                            Compose a one-off message to any recipient without a template.
                        </p>
                        <p className={'text-xs text-neutral-400'}>
                            Great for quick announcements or support replies. Uses your configured From settings.
                        </p>
                    </div>
                    <div className={'flex gap-2'}>
                        <Button
                            variant={Button.Variants.Secondary}
                            disabled={!isOpen}
                            onClick={() => {
                                setTo('');
                                setSubject('');
                                setHtml('');
                                setText('');
                            }}
                        >
                            Clear fields
                        </Button>
                        <Button onClick={() => setIsOpen(true)}>Open composer</Button>
                    </div>
                </div>
            </AdminBox>

            <Modal visible={isOpen} onDismissed={() => setIsOpen(false)} dismissable>
                <div className={'space-y-4'}>
                    <div className={'flex items-center justify-between gap-3'}>
                        <div>
                            <h3 className={'text-lg font-semibold text-white'}>Custom email composer</h3>
                            <p className={'text-sm text-neutral-400'}>
                                Fill out the fields below to send a single ad-hoc email.
                            </p>
                        </div>
                        <span className={'rounded-full bg-amber-500/20 px-3 py-1 text-xs font-semibold text-amber-200'}>
                            Manual send
                        </span>
                    </div>

                    <div className={'grid gap-4 md:grid-cols-2'}>
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
                        <div>
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
                    </div>

                    <div>
                        <Label>HTML Content *</Label>
                        <Textarea
                            placeholder={'<h1>Hello</h1><p>Your HTML email content here...</p>'}
                            id={'html'}
                            name={'html'}
                            value={html}
                            onChange={e => setHtml(e.target.value)}
                            rows={8}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            The HTML content of the email. You can use any valid HTML.
                        </p>
                    </div>

                    <div>
                        <Label>Plain Text Content (Optional)</Label>
                        <Textarea
                            placeholder={'Plain text version (auto-generated if not provided)'}
                            id={'text'}
                            name={'text'}
                            value={text}
                            onChange={e => setText(e.target.value)}
                            rows={4}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            Optional plain text version. If not provided, it will be auto-generated from the HTML.
                        </p>
                    </div>

                    <div className={'flex flex-col gap-3 rounded border border-neutral-700 bg-neutral-900/50 p-3 text-sm'}>
                        <div className={'flex items-center gap-2 text-amber-300'}>
                            Emails will respect your From / Reply-To settings and are logged in activity history.
                        </div>
                        <div className={'flex items-center gap-2 text-neutral-300'}>
                            Remember to include unsubscribe language if required for your audience.
                        </div>
                    </div>

                    <div className={'flex justify-end gap-3'}>
                        <Button variant={Button.Variants.Secondary} onClick={() => setIsOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleSend} disabled={!to || !subject || !html} loading={status === 'loading'}>
                            Send Email
                        </Button>
                    </div>
                </div>
            </Modal>
        </>
    );
};
