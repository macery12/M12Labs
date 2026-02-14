import { useState, useEffect } from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faShieldAlt, faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import { Button } from '@/elements/button';
import ContentBox from '@/elements/ContentBox';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import http from '@/api/http';
import useFlash from '@/plugins/useFlash';

interface RecoveryCodeStatus {
    has_recovery_code: boolean;
    already_downloaded: boolean;
    can_download: boolean;
}

export default function RecoveryCodeSection() {
    const [status, setStatus] = useState<RecoveryCodeStatus | null>(null);
    const [loading, setLoading] = useState(true);
    const [downloading, setDownloading] = useState(false);
    const { clearFlashes, addFlash } = useFlash();

    useEffect(() => {
        checkStatus();
    }, []);

    const checkStatus = () => {
        setLoading(true);
        http.get('/api/client/account/recovery-code/status')
            .then(({ data }) => setStatus(data))
            .catch(err => {
                console.error('Failed to check recovery code status:', err);
            })
            .finally(() => setLoading(false));
    };

    const downloadRecoveryCode = () => {
        setDownloading(true);
        clearFlashes('account:recovery-code');

        http.get('/api/client/account/recovery-code')
            .then(({ data }) => {
                const regenerated = data.regenerated || false;
                const warningText = regenerated 
                    ? '\n\n⚠️ NOTE: Your recovery code was regenerated because the previous one was corrupted.\nThis is your NEW recovery code. The old one is no longer valid.'
                    : '';
                    
                const content = `RECOVERY CODE - KEEP THIS SAFE!\n\nYour account recovery code:\n${data.recovery_code}\n\nThis code can be used to recover your account if you lose access.\nStore it in a safe place. Do not share it with anyone.\n\nGenerated: ${new Date().toLocaleString()}\n\nWARNING: This code can only be downloaded once. Keep it safe!${warningText}`;
                
                const blob = new Blob([content], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'recovery-code.txt';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);

                const successMsg = regenerated
                    ? 'Recovery code downloaded! Note: A new code was generated because the previous one was corrupted.'
                    : 'Recovery code downloaded successfully! Keep it safe.';

                addFlash({
                    key: 'account:recovery-code',
                    type: 'success',
                    message: successMsg,
                });

                // Update status
                checkStatus();
            })
            .catch(err => {
                console.error('Failed to download recovery code:', err);
                const message = err.response?.data?.error || 'Failed to download recovery code.';
                addFlash({
                    key: 'account:recovery-code',
                    type: 'error',
                    message,
                });
            })
            .finally(() => setDownloading(false));
    };

    return (
        <ContentBox title="Recovery Code" showFlashes="account:recovery-code">
            <SpinnerOverlay visible={loading} />
            
            {status && (
                <div>
                    {status.can_download ? (
                        <>
                            <div css={tw`mb-4`}>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faShieldAlt} css={tw`text-yellow-400 mr-3 mt-1 text-xl`} />
                                    <div css={tw`flex-1`}>
                                        <p css={tw`text-sm text-gray-300 mb-2`}>
                                            Your recovery code can be used to regain access to your account if you lose your password or 2FA device.
                                        </p>
                                        <p css={tw`text-sm text-yellow-400 font-medium`}>
                                            ⚠️ This code can only be downloaded once. Keep it in a safe place!
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div css={tw`flex justify-center`}>
                                <Button.Success
                                    onClick={downloadRecoveryCode}
                                    disabled={downloading}
                                >
                                    <FontAwesomeIcon icon={faDownload} css={tw`mr-2`} />
                                    {downloading ? 'Downloading...' : 'Download Recovery Code'}
                                </Button.Success>
                            </div>
                        </>
                    ) : status.already_downloaded ? (
                        <div css={tw`flex items-center text-green-400`}>
                            <FontAwesomeIcon icon={faCheckCircle} css={tw`mr-3 text-xl`} />
                            <div>
                                <p css={tw`font-medium`}>Recovery code already downloaded</p>
                                <p css={tw`text-sm text-gray-400 mt-1`}>
                                    You have already downloaded your recovery code. Keep it safe!
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div css={tw`text-gray-400 text-sm`}>
                            <p>No recovery code available.</p>
                        </div>
                    )}
                </div>
            )}
        </ContentBox>
    );
}
