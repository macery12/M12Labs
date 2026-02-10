import { Button } from '@/elements/button';
import tw from 'twin.macro';
import { useNavigate } from 'react-router-dom';

interface AdminBypassButtonProps {
    serverUuid: string;
    bypassType: 'suspended' | 'conflict';
}

const AdminBypassButton = ({ serverUuid, bypassType }: AdminBypassButtonProps) => {
    const navigate = useNavigate();

    const handleAdminBypass = () => {
        // Store bypass state in session storage
        sessionStorage.setItem(`admin_bypass_${bypassType}_${serverUuid}`, 'true');
        // Navigate to the server to trigger reload
        navigate(`/server/${serverUuid}`);
    };

    return (
        <div css={tw`mt-4`}>
            <Button onClick={handleAdminBypass} size={Button.Sizes.Large} variant={Button.Variants.Secondary}>
                Admin Bypass
            </Button>
            <p css={tw`text-xs text-neutral-500 mt-2`}>
                This will bypass the {bypassType} screen without changing the server state.
            </p>
        </div>
    );
};

export default AdminBypassButton;
