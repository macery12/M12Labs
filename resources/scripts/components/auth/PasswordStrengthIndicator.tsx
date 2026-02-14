import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/solid';

interface Props {
    password: string;
    onValidityChange?: (isValid: boolean) => void;
}

interface RequirementState {
    met: boolean;
    label: string;
}

export default ({ password, onValidityChange }: Props) => {
    const [requirements, setRequirements] = useState<RequirementState[]>([
        { met: false, label: '8 characters or more' },
        { met: false, label: 'Contains uppercase letter' },
        { met: false, label: 'Contains lowercase letter' },
        { met: false, label: 'Contains number' },
        { met: false, label: 'Contains special character (!@#$%^&* etc.)' },
    ]);

    useEffect(() => {
        const newRequirements = [
            { met: password.length >= 8, label: '8 characters or more' },
            { met: /[A-Z]/.test(password), label: 'Contains uppercase letter' },
            { met: /[a-z]/.test(password), label: 'Contains lowercase letter' },
            { met: /[0-9]/.test(password), label: 'Contains number' },
            { met: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password), label: 'Contains special character (!@#$%^&* etc.)' },
        ];

        setRequirements(newRequirements);

        const allMet = newRequirements.every(req => req.met);
        onValidityChange?.(allMet);
    }, [password, onValidityChange]);

    if (!password) {
        return null;
    }

    return (
        <div css={tw`mt-2 p-3 bg-neutral-700/50 rounded-md border border-neutral-600`}>
            <p css={tw`text-xs text-neutral-300 font-medium mb-2`}>Password Requirements:</p>
            <div css={tw`space-y-1`}>
                {requirements.map((req, index) => (
                    <div key={index} css={tw`flex items-center text-xs`}>
                        {req.met ? (
                            <CheckCircleIcon css={tw`w-4 h-4 text-green-400 mr-2 flex-shrink-0`} />
                        ) : (
                            <XCircleIcon css={tw`w-4 h-4 text-neutral-500 mr-2 flex-shrink-0`} />
                        )}
                        <span css={req.met ? tw`text-green-300` : tw`text-neutral-400`}>{req.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
};
