import { useState, useEffect } from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDiscord } from '@fortawesome/free-brands-svg-icons';
import { faLink, faUnlink, faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import { Button } from '@/elements/button';
import ContentBox from '@/elements/ContentBox';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import http from '@/api/http';
import useFlash from '@/plugins/useFlash';
import { Dialog } from '@/elements/dialog';

export default function DiscordLinkingSection() {
    const [hasDiscord, setHasDiscord] = useState(false);
    const [loading, setLoading] = useState(true);
    const [unlinking, setUnlinking] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const { clearFlashes, addFlash } = useFlash();

    useEffect(() => {
        checkDiscordStatus();
    }, []);

    const checkDiscordStatus = () => {
        setLoading(true);
        http.get('/api/client/account')
            .then(({ data }) => {
                setHasDiscord(data.attributes.has_discord_linked || false);
            })
            .catch(err => {
                console.error('Failed to check Discord status:', err);
            })
            .finally(() => setLoading(false));
    };

    const linkDiscord = () => {
        clearFlashes('account:discord');
        
        // Get Discord OAuth URL from the account endpoint (no recaptcha needed)
        http.get('/api/client/account/discord/link-url')
            .then(({ data }) => {
                // Redirect to Discord OAuth
                window.location.href = data.url;
            })
            .catch(err => {
                console.error('Failed to start Discord linking:', err);
                addFlash({
                    key: 'account:discord',
                    type: 'error',
                    message: 'Failed to start Discord linking process.',
                });
            });
    };

    const unlinkDiscord = () => {
        setUnlinking(true);
        clearFlashes('account:discord');

        http.delete('/api/client/account/discord')
            .then(() => {
                addFlash({
                    key: 'account:discord',
                    type: 'success',
                    message: 'Discord account unlinked successfully.',
                });
                setHasDiscord(false);
                setShowConfirm(false);
            })
            .catch(err => {
                console.error('Failed to unlink Discord:', err);
                const message = err.response?.data?.error || 'Failed to unlink Discord account.';
                addFlash({
                    key: 'account:discord',
                    type: 'error',
                    message,
                });
            })
            .finally(() => setUnlinking(false));
    };

    return (
        <>
            <Dialog
                open={showConfirm}
                onClose={() => setShowConfirm(false)}
                title="Unlink Discord Account"
                description="Are you sure you want to unlink your Discord account? You can always link it again later."
            >
                <div css={tw`mt-6 flex justify-end gap-4`}>
                    <Button onClick={() => setShowConfirm(false)}>
                        Cancel
                    </Button>
                    <Button.Danger onClick={unlinkDiscord} disabled={unlinking}>
                        {unlinking ? 'Unlinking...' : 'Unlink Discord'}
                    </Button.Danger>
                </div>
            </Dialog>

            <ContentBox title="Discord Account" showFlashes="account:discord">
                <SpinnerOverlay visible={loading} />
                
                <div>
                    {hasDiscord ? (
                        <>
                            <div css={tw`mb-4 flex items-center text-green-400`}>
                                <FontAwesomeIcon icon={faCheckCircle} css={tw`mr-3 text-xl`} />
                                <div>
                                    <p css={tw`font-medium`}>Discord account linked</p>
                                    <p css={tw`text-sm text-gray-400 mt-1`}>
                                        Your Discord account is linked for easy login and 2FA recovery.
                                    </p>
                                </div>
                            </div>
                            
                            <div css={tw`flex justify-center`}>
                                <Button.Danger
                                    onClick={() => setShowConfirm(true)}
                                >
                                    <FontAwesomeIcon icon={faUnlink} css={tw`mr-2`} />
                                    Unlink Discord Account
                                </Button.Danger>
                            </div>
                        </>
                    ) : (
                        <>
                            <div css={tw`mb-4`}>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faDiscord} css={tw`text-indigo-400 mr-3 mt-1 text-xl`} />
                                    <div css={tw`flex-1`}>
                                        <p css={tw`text-sm text-gray-300 mb-2`}>
                                            Link your Discord account for easier login and as a backup 2FA recovery method.
                                        </p>
                                        <p css={tw`text-sm text-gray-400`}>
                                            Once linked, you'll be able to use Discord for quick authentication.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div css={tw`flex justify-center`}>
                                <Button.Info
                                    onClick={linkDiscord}
                                >
                                    <FontAwesomeIcon icon={faLink} css={tw`mr-2`} />
                                    Link Discord Account
                                </Button.Info>
                            </div>
                        </>
                    )}
                </div>
            </ContentBox>
        </>
    );
}
