import { useEffect, useState } from 'react';
import { Actions, useStoreActions } from 'easy-peasy';
import tw from 'twin.macro';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import { Button } from '@/elements/button';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { getDiscordLinkStatus, getDiscordLinkUrl, unlinkDiscord } from '@/api/routes/account/discord';
import type { DiscordLinkStatus } from '@/api/routes/account/discord';

export default () => {
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [status, setStatus] = useState<DiscordLinkStatus | null>(null);

    const { clearFlashes, addFlash } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    useEffect(() => {
        clearFlashes('account:discord');
        loadStatus();
    }, []);

    const loadStatus = () => {
        setLoading(true);
        getDiscordLinkStatus()
            .then(setStatus)
            .catch(error => {
                console.error(error);
                addFlash({
                    type: 'error',
                    key: 'account:discord',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => setLoading(false));
    };

    const handleLink = () => {
        setSubmitting(true);
        clearFlashes('account:discord');

        getDiscordLinkUrl()
            .then(url => {
                window.location.href = url;
            })
            .catch(error => {
                console.error(error);
                addFlash({
                    type: 'error',
                    key: 'account:discord',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
                setSubmitting(false);
            });
    };

    const handleUnlink = () => {
        setSubmitting(true);
        clearFlashes('account:discord');

        unlinkDiscord()
            .then(() => {
                addFlash({
                    type: 'success',
                    key: 'account:discord',
                    message: 'Your Discord account has been unlinked.',
                });
                loadStatus();
            })
            .catch(error => {
                console.error(error);
                addFlash({
                    type: 'error',
                    key: 'account:discord',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => setSubmitting(false));
    };

    if (loading) {
        return <SpinnerOverlay size="large" visible />;
    }

    return (
        <div css={tw`relative`}>
            <SpinnerOverlay size="large" visible={submitting} />
            {status?.linked ? (
                <div>
                    <div css={tw`flex items-center mb-6`}>
                        {status.discord_avatar && (
                            <img
                                src={`https://cdn.discordapp.com/avatars/${status.discord_id}/${status.discord_avatar}.png`}
                                alt="Discord Avatar"
                                css={tw`w-12 h-12 rounded-full mr-4`}
                            />
                        )}
                        <div>
                            <p css={tw`text-sm text-neutral-300`}>Connected as</p>
                            <p css={tw`text-lg font-semibold`}>{status.discord_username}</p>
                        </div>
                    </div>
                    <Button.Danger onClick={handleUnlink} disabled={submitting}>
                        Unlink Discord Account
                    </Button.Danger>
                </div>
            ) : (
                <div>
                    <p css={tw`text-sm text-neutral-300 mb-6`}>
                        Connect your Discord account to enable additional security features and easier account
                        recovery.
                    </p>
                    <Button onClick={handleLink} disabled={submitting}>
                        Link Discord Account
                    </Button>
                </div>
            )}
        </div>
    );
};
