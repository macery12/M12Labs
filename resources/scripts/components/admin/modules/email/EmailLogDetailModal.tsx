import { useEffect, useState } from 'react';
import { getEmailLog, resendEmail, type EmailLogDetail } from '@/api/routes/admin/email';
import useFlash from '@/plugins/useFlash';
import Modal from '@/elements/Modal';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import tw from 'twin.macro';
import styled from 'styled-components';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCheckCircle,
    faTimesCircle,
    faClock,
    faCopy,
    faRedo,
    faExternalLinkAlt,
} from '@fortawesome/free-solid-svg-icons';

interface Props {
    logId: number;
    onClose: () => void;
}

const ModalContent = styled.div`
    ${tw`bg-gray-900 rounded-lg p-6 max-w-5xl w-full max-h-[90vh] overflow-y-auto`}
`;

const Section = styled.div`
    ${tw`mb-6 pb-6 border-b border-gray-700 last:border-b-0`}
`;

const SectionTitle = styled.h3`
    ${tw`text-lg font-semibold mb-4 text-gray-200`}
`;

const DetailGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 gap-4`}
`;

const DetailItem = styled.div`
    ${tw`flex flex-col`}
`;

const DetailLabel = styled.span`
    ${tw`text-xs font-medium text-gray-400 uppercase mb-1`}
`;

const DetailValue = styled.span`
    ${tw`text-sm text-gray-200`}
`;

const CodeBlock = styled.pre`
    ${tw`bg-gray-900 p-4 rounded text-xs overflow-x-auto text-green-400 font-mono`}
`;

const StatusBadge = styled.span<{ success: boolean }>`
    ${tw`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium`}
    ${(props) => (props.success ? tw`bg-green-900 text-green-300` : tw`bg-red-900 text-red-300`)}
`;

const Timeline = styled.div`
    ${tw`space-y-4`}
`;

const TimelineItem = styled.div`
    ${tw`flex items-start`}
`;

const TimelineIcon = styled.div<{ success: boolean }>`
    ${tw`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center mr-4`}
    ${(props) => (props.success ? tw`bg-green-900 text-green-400` : tw`bg-red-900 text-red-400`)}
`;

const TimelineContent = styled.div`
    ${tw`flex-1`}
`;

export default ({ logId, onClose }: Props) => {
    const [loading, setLoading] = useState(true);
    const [resending, setResending] = useState(false);
    const [detail, setDetail] = useState<EmailLogDetail | null>(null);
    const { addFlash } = useFlash();

    useEffect(() => {
        loadDetail();
    }, [logId]);

    const loadDetail = async () => {
        setLoading(true);
        try {
            const data = await getEmailLog(logId);
            setDetail(data);
        } catch (error: any) {
            addFlash({
                key: 'email:activity',
                type: 'error',
                message: error.message || 'Failed to load email details',
            });
            onClose();
        } finally {
            setLoading(false);
        }
    };

    const handleResend = async () => {
        if (!detail || detail.log.success) return;

        setResending(true);
        try {
            const result = await resendEmail(logId);
            addFlash({
                key: 'email:activity',
                type: 'success',
                message: result.message,
            });
            onClose();
        } catch (error: any) {
            addFlash({
                key: 'email:activity',
                type: 'error',
                message: error.message || 'Failed to resend email',
            });
        } finally {
            setResending(false);
        }
    };

    const copyDebugBundle = () => {
        if (!detail) return;

        const bundle = {
            log: detail.log,
            sanitized_variables: detail.sanitized_variables,
            retry_history: detail.retry_history,
            timestamp: new Date().toISOString(),
        };

        navigator.clipboard.writeText(JSON.stringify(bundle, null, 2));
        addFlash({
            key: 'email:activity',
            type: 'success',
            message: 'Debug bundle copied to clipboard',
        });
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleString();
    };

    return (
        <Modal visible={true} onDismissed={onClose} dismissable={true}>
            <ModalContent>
                {loading ? (
                    <div className='flex items-center justify-center py-12'>
                        <Spinner size='large' />
                    </div>
                ) : detail ? (
                    <>
                        <div className='flex items-center justify-between mb-6'>
                            <h2 className='text-2xl font-bold'>Email Log Details #{detail.log.id}</h2>
                            <div className='flex gap-2'>
                                {!detail.log.success && (
                                    <Button onClick={handleResend} disabled={resending} variant='primary' size='sm'>
                                        <FontAwesomeIcon icon={faRedo} className='mr-2' />
                                        {resending ? 'Resending...' : 'Resend'}
                                    </Button>
                                )}
                                <Button onClick={copyDebugBundle} variant='secondary' size='sm'>
                                    <FontAwesomeIcon icon={faCopy} className='mr-2' />
                                    Copy Debug Bundle
                                </Button>
                            </div>
                        </div>

                        {/* Basic Information */}
                        <Section>
                            <SectionTitle>Basic Information</SectionTitle>
                            <DetailGrid>
                                <DetailItem>
                                    <DetailLabel>Status</DetailLabel>
                                    <div>
                                        <StatusBadge success={detail.log.success}>
                                            <FontAwesomeIcon
                                                icon={detail.log.success ? faCheckCircle : faTimesCircle}
                                                className='mr-2'
                                            />
                                            {detail.log.status}
                                        </StatusBadge>
                                    </div>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Sent At</DetailLabel>
                                    <DetailValue>{formatDate(detail.log.created_at)}</DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Recipient</DetailLabel>
                                    <DetailValue>{detail.log.to}</DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>User</DetailLabel>
                                    <DetailValue>
                                        {detail.log.user
                                            ? `${detail.log.user.username} (${detail.log.user.email})`
                                            : 'N/A'}
                                    </DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Subject</DetailLabel>
                                    <DetailValue>{detail.log.subject}</DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Template Key</DetailLabel>
                                    <DetailValue>
                                        <code className='bg-gray-800 px-2 py-1 rounded text-blue-400'>
                                            {detail.log.template_key || 'custom'}
                                        </code>
                                    </DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Provider</DetailLabel>
                                    <DetailValue>{detail.log.provider}</DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Message ID</DetailLabel>
                                    <DetailValue className='text-xs font-mono'>
                                        {detail.log.message_id || 'N/A'}
                                    </DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Correlation ID</DetailLabel>
                                    <DetailValue className='text-xs font-mono'>
                                        {detail.log.correlation_id || 'N/A'}
                                    </DetailValue>
                                </DetailItem>

                                <DetailItem>
                                    <DetailLabel>Attempts</DetailLabel>
                                    <DetailValue>{detail.log.attempt_count}</DetailValue>
                                </DetailItem>

                                {detail.log.duration_ms && (
                                    <DetailItem>
                                        <DetailLabel>Duration</DetailLabel>
                                        <DetailValue>{detail.log.duration_ms}ms</DetailValue>
                                    </DetailItem>
                                )}
                            </DetailGrid>
                        </Section>

                        {/* Error Details */}
                        {detail.log.error && (
                            <Section>
                                <SectionTitle>Error Details</SectionTitle>
                                <CodeBlock>{detail.log.error}</CodeBlock>
                            </Section>
                        )}

                        {/* Template Variables */}
                        {Object.keys(detail.sanitized_variables).length > 0 && (
                            <Section>
                                <SectionTitle>Template Variables (Sanitized)</SectionTitle>
                                <CodeBlock>{JSON.stringify(detail.sanitized_variables, null, 2)}</CodeBlock>
                            </Section>
                        )}

                        {/* Metadata */}
                        {detail.log.metadata && Object.keys(detail.log.metadata).length > 0 && (
                            <Section>
                                <SectionTitle>Metadata</SectionTitle>
                                <CodeBlock>{JSON.stringify(detail.log.metadata, null, 2)}</CodeBlock>
                            </Section>
                        )}

                        {/* Retry History */}
                        {detail.retry_history.length > 0 && (
                            <Section>
                                <SectionTitle>Retry History</SectionTitle>
                                <Timeline>
                                    {detail.retry_history.map((attempt, idx) => (
                                        <TimelineItem key={idx}>
                                            <TimelineIcon success={!attempt.error}>
                                                <FontAwesomeIcon
                                                    icon={attempt.error ? faTimesCircle : faCheckCircle}
                                                />
                                            </TimelineIcon>
                                            <TimelineContent>
                                                <div className='text-sm font-medium text-gray-200'>
                                                    Attempt #{attempt.attempt}
                                                </div>
                                                <div className='text-xs text-gray-400'>
                                                    {formatDate(attempt.timestamp)}
                                                </div>
                                                {attempt.error && (
                                                    <div className='mt-2 text-xs text-red-400 bg-red-900/20 p-2 rounded'>
                                                        {attempt.error}
                                                    </div>
                                                )}
                                            </TimelineContent>
                                        </TimelineItem>
                                    ))}
                                </Timeline>
                            </Section>
                        )}

                        {/* Related Emails */}
                        {detail.related_emails.length > 0 && (
                            <Section>
                                <SectionTitle>Related Emails (Same Correlation ID)</SectionTitle>
                                <div className='space-y-2'>
                                    {detail.related_emails.map((email) => (
                                        <div
                                            key={email.id}
                                            className='bg-gray-800 p-3 rounded flex items-center justify-between'
                                        >
                                            <div>
                                                <div className='text-sm font-medium text-gray-200'>
                                                    {email.subject}
                                                </div>
                                                <div className='text-xs text-gray-400'>
                                                    {email.to} • {formatDate(email.created_at)} •{' '}
                                                    <code className='text-blue-400'>{email.template_key}</code>
                                                </div>
                                            </div>
                                            <span className='text-xs px-2 py-1 rounded bg-gray-700 text-gray-300'>
                                                {email.status}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Section>
                        )}
                    </>
                ) : null}
            </ModalContent>
        </Modal>
    );
};
