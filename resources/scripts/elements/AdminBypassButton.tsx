import { Button } from '@/elements/button';
import tw from 'twin.macro';
import { useNavigate } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationCircle } from '@fortawesome/free-solid-svg-icons';

interface AdminBypassButtonProps {
    serverUuid: string;
    bypassType: 'suspended' | 'conflict';
    serverStatus?: string | null;
    position?: 'inline' | 'absolute';
}

const AdminBypassButton = ({ serverUuid, bypassType, serverStatus, position = 'inline' }: AdminBypassButtonProps) => {
    const navigate = useNavigate();

    // Check if server is actually suspended (Wings disables access)
    const isServerSuspended = serverStatus === 'suspended';

    // For suspended bypass type, only allow bypass if server is not actually suspended
    // (i.e., blocked due to billing but not suspended by Wings)
    const isDisabled = bypassType === 'suspended' && isServerSuspended;

    const handleAdminBypass = () => {
        if (isDisabled) return;

        // Store bypass state in session storage
        sessionStorage.setItem(`admin_bypass_${bypassType}_${serverUuid}`, 'true');
        // Navigate to the server to trigger reload
        navigate(`/server/${serverUuid}`);
    };

    const containerStyles = position === 'absolute' ? tw`absolute top-0 right-0 mt-4 mr-4 z-10` : tw`mt-4`;

    return (
        <div css={containerStyles}>
            <Button
                onClick={handleAdminBypass}
                size={Button.Sizes.Large}
                variant={Button.Variants.Secondary}
                disabled={isDisabled}
            >
                Admin Bypass
            </Button>
            {isDisabled ? (
                <div css={tw`mt-2 flex items-start gap-2 max-w-xs`}>
                    <FontAwesomeIcon icon={faExclamationCircle} css={tw`text-red-400 text-sm mt-0.5 flex-shrink-0`} />
                    <p css={tw`text-xs text-red-300`}>
                        Server is suspended by Wings and cannot be accessed. Bypass is only available for
                        billing-related blocks.
                    </p>
                </div>
            ) : (
                <p css={tw`text-xs text-neutral-500 mt-2 max-w-xs`}>
                    This will bypass the {bypassType} screen without changing the server state.
                </p>
            )}
        </div>
    );
};

export default AdminBypassButton;
