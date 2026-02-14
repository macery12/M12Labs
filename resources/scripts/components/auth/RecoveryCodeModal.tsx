import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faShieldAlt } from '@fortawesome/free-solid-svg-icons';
import { Dialog } from '@/elements/dialog';
import { Button } from '@/elements/button';
import { getRecoveryCode } from '@/api/routes/account';
import SpinnerOverlay from '@/elements/SpinnerOverlay';

interface Props {
    visible: boolean;
    onDismissed: () => void;
}

export default function RecoveryCodeModal({ visible, onDismissed }: Props) {
    const [recoveryCode, setRecoveryCode] = useState<string>('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (visible) {
            setLoading(true);
            setError(null);
            
            getRecoveryCode()
                .then(data => {
                    setRecoveryCode(data.recovery_code);
                })
                .catch(err => {
                    console.error('Failed to fetch recovery code:', err);
                    setError('Failed to load recovery code. Please try again later.');
                })
                .finally(() => setLoading(false));
        }
    }, [visible]);

    const downloadRecoveryCode = () => {
        const content = `RECOVERY CODE - KEEP THIS SAFE!\n\nYour account recovery code:\n${recoveryCode}\n\nThis code can be used to recover your account if you lose access.\nStore it in a safe place. Do not share it with anyone.\n\nGenerated: ${new Date().toLocaleString()}`;
        
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'recovery-code.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    return (
        <Dialog
            open={visible}
            onClose={() => {}}
            title="Save Your Recovery Code"
            description="Important: Download and store your recovery code in a safe place. You will need this to recover your account if you lose access."
        >
            <SpinnerOverlay visible={loading} />
            
            <div css={tw`mt-6`}>
                {error ? (
                    <div css={tw`bg-red-500/10 border border-red-500 rounded p-4 text-red-400`}>
                        {error}
                    </div>
                ) : (
                    <>
                        <div css={tw`bg-neutral-800 border border-neutral-700 rounded-lg p-6 mb-6`}>
                            <div css={tw`flex items-center mb-4`}>
                                <FontAwesomeIcon icon={faShieldAlt} css={tw`text-green-400 mr-3 text-2xl`} />
                                <div>
                                    <h3 css={tw`text-lg font-medium text-neutral-100`}>Your Recovery Code</h3>
                                    <p css={tw`text-sm text-neutral-400 mt-1`}>
                                        Use this code to recover your account if you lose access
                                    </p>
                                </div>
                            </div>
                            
                            <div css={tw`bg-neutral-900 border border-neutral-600 rounded p-4 font-mono text-lg text-center tracking-wider`}>
                                {recoveryCode}
                            </div>
                        </div>

                        <div css={tw`bg-yellow-500/10 border border-yellow-500/50 rounded p-4 mb-6`}>
                            <p css={tw`text-sm text-yellow-400`}>
                                <strong>Important:</strong> Download and store this recovery code in a safe place. 
                                You won't be able to see it again after closing this window.
                            </p>
                        </div>

                        <div css={tw`flex justify-end gap-4`}>
                            <Button.Success onClick={downloadRecoveryCode} size={Button.Sizes.Large}>
                                <FontAwesomeIcon icon={faDownload} css={tw`mr-2`} />
                                Download Recovery Code
                            </Button.Success>
                            <Button onClick={onDismissed} size={Button.Sizes.Large}>
                                I've Saved It
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </Dialog>
    );
}
