import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck, faTimes, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

interface Props {
    password: string;
    showPwnedWarning?: boolean;
}

interface PasswordRequirement {
    label: string;
    test: (password: string) => boolean;
}

const requirements: PasswordRequirement[] = [
    { label: 'At least 8 characters', test: (pwd) => pwd.length >= 8 },
    { label: 'Contains uppercase letter', test: (pwd) => /[A-Z]/.test(pwd) },
    { label: 'Contains lowercase letter', test: (pwd) => /[a-z]/.test(pwd) },
    { label: 'Contains number', test: (pwd) => /[0-9]/.test(pwd) },
    { label: 'Contains special character', test: (pwd) => /[^A-Za-z0-9]/.test(pwd) },
];

export default function PasswordStrengthIndicator({ password, showPwnedWarning = false }: Props) {
    const [pwnedStatus, setPwnedStatus] = useState<'checking' | 'safe' | 'pwned' | 'error' | null>(null);
    const [pwnedCount, setPwnedCount] = useState<number>(0);

    useEffect(() => {
        if (!password || password.length < 1 || !showPwnedWarning) {
            setPwnedStatus(null);
            return;
        }

        // Debounce the API call
        const timer = setTimeout(() => {
            checkPasswordPwned(password);
        }, 1000);

        return () => clearTimeout(timer);
    }, [password, showPwnedWarning]);

    const checkPasswordPwned = async (pwd: string) => {
        try {
            setPwnedStatus('checking');

            // Hash the password using SHA-1
            const encoder = new TextEncoder();
            const data = encoder.encode(pwd);
            const hashBuffer = await crypto.subtle.digest('SHA-1', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();

            // Use k-anonymity: only send first 5 characters
            const prefix = hashHex.substring(0, 5);
            const suffix = hashHex.substring(5);

            const response = await fetch(`https://api.pwnedpasswords.com/range/${prefix}`);
            const text = await response.text();

            // Check if our suffix is in the response
            const lines = text.split('\n');
            const found = lines.find(line => line.startsWith(suffix));

            if (found) {
                const count = parseInt(found.split(':')[1], 10);
                setPwnedCount(count);
                setPwnedStatus('pwned');
            } else {
                setPwnedStatus('safe');
            }
        } catch (error) {
            console.error('Error checking password against HIBP:', error);
            setPwnedStatus('error');
        }
    };

    if (!password || password.length < 1) {
        return null;
    }

    return (
        <div css={tw`mt-3 space-y-2`}>
            <div css={tw`text-sm font-medium text-gray-300`}>Password Requirements:</div>
            <div css={tw`space-y-1`}>
                {requirements.map((req, index) => {
                    const passed = req.test(password);
                    return (
                        <div key={index} css={tw`flex items-center text-sm`}>
                            <FontAwesomeIcon
                                icon={passed ? faCheck : faTimes}
                                css={[tw`mr-2`, passed ? tw`text-green-400` : tw`text-red-400`]}
                            />
                            <span css={[passed ? tw`text-green-400` : tw`text-gray-400`]}>{req.label}</span>
                        </div>
                    );
                })}
            </div>

            {showPwnedWarning && pwnedStatus && (
                <div css={tw`mt-3 pt-3 border-t border-gray-700`}>
                    {pwnedStatus === 'checking' && (
                        <div css={tw`flex items-center text-sm text-gray-400`}>
                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2 animate-pulse`} />
                            <span>Checking password security...</span>
                        </div>
                    )}
                    {pwnedStatus === 'pwned' && (
                        <div css={tw`flex items-start text-sm text-yellow-400`}>
                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2 mt-0.5`} />
                            <div>
                                <div css={tw`font-medium`}>Warning: This password has been compromised!</div>
                                <div css={tw`text-xs mt-1 text-gray-400`}>
                                    This password appeared {pwnedCount.toLocaleString()} times in data breaches.
                                    Consider using a different password for better security.
                                </div>
                            </div>
                        </div>
                    )}
                    {pwnedStatus === 'safe' && (
                        <div css={tw`flex items-center text-sm text-green-400`}>
                            <FontAwesomeIcon icon={faCheck} css={tw`mr-2`} />
                            <span>This password has not been found in known data breaches.</span>
                        </div>
                    )}
                    {pwnedStatus === 'error' && (
                        <div css={tw`flex items-center text-sm text-gray-400`}>
                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-2`} />
                            <span>Unable to check password security. You may continue.</span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
