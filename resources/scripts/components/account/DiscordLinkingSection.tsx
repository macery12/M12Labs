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
import { useStoreActions, useStoreState } from '@/state/hooks';

export default function DiscordLinkingSection() {
    const user = useStoreState((state) => state.user.data);
    const mutateUser = useStoreActions((actions) => actions.user.setUserData);
    const [loading, setLoading] = useState(false);
    const [unlinking, setUnlinking] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const { clearFlashes, addFlash } = useFlash();

    // Check if we just returned from Discord OAuth
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('discord_linked') === 'success') {
            addFlash({
                key: 'account:discord',
                type: 'success',
                message: 'Discord account linked successfully!',
            });
            
            // Refresh user data
            refreshUserData();
            
            // Clean up URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }, []);

    const refreshUserData = () => {
        http.get('/api/client/account')
            .then(({ data }) => {
                mutateUser(data.attributes);
            })
            .catch(err => {
                console.error('Failed to refresh user data:', err);
            });
    };

    const linkDiscord = () => {
        setLoading(true);
        clearFlashes('account:discord');
        
        // Get Discord OAuth URL from the account endpoint (no recaptcha needed)
        http.get('/api/client/account/discord/link-url')
            .then(({ data }) => {
                // Redirect to Discord OAuth
                window.location.href = data.url;
            })
            .catch(err => {
                console.error('Failed to start Discord linking:', err);
                const message = err.response?.data?.error || 'Failed to start Discord linking process.';
                addFlash({
                    key: 'account:discord',
                    type: 'error',
                    message,
                });
                setLoading(false);
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
                setShowConfirm(false);
                refreshUserData();
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

    const hasDiscord = user?.has_discord_linked || false;
    const discordUsername = user?.discord_username;
    const discordAvatar = user?.discord_avatar;

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
                            <div css={tw`mb-4`}>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`text-green-400 mr-3 mt-1 text-xl`} />
                                    <div css={tw`flex-1`}>
                                        <p css={tw`font-medium text-green-400`}>Discord account linked</p>
                                        {discordUsername && (
                                            <div css={tw`flex items-center mt-2`}>
                                                {discordAvatar && (
                                                    <img
                                                        src={`https://cdn.discordapp.com/avatars/${user?.external_id}/${discordAvatar}.png?size=64`}
                                                        alt="Discord avatar"
                                                        css={tw`w-10 h-10 rounded-full mr-3`}
                                                    />
                                                )}
                                                <div>
                                                    <p css={tw`text-sm text-gray-300`}>{discordUsername}</p>
                                                    <p css={tw`text-xs text-gray-500`}>Linked for login & 2FA recovery</p>
                                                </div>
                                            </div>
                                        )}
                                        {!discordUsername && (
                                            <p css={tw`text-sm text-gray-400 mt-1`}>
                                                Your Discord account is linked for easy login and 2FA recovery.
                                            </p>
                                        )}
                                    </div>
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
