import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faDownload, faFileArchive, faFolderOpen } from '@fortawesome/free-solid-svg-icons';

interface Props {
    onComplete: () => void;
}

export default ({ onComplete }: Props) => {
    const [currentStep, setCurrentStep] = useState(0);
    const [steps] = useState([
        { icon: faDownload, label: 'Downloading modpack archive...', duration: 3000 },
        { icon: faFileArchive, label: 'Extracting modpack files...', duration: 2000 },
        { icon: faFolderOpen, label: 'Installing mods to server...', duration: 3000 },
        { icon: faCheckCircle, label: 'Installation complete!', duration: 1000 },
    ]);

    useEffect(() => {
        if (currentStep < steps.length - 1) {
            const timer = setTimeout(() => {
                setCurrentStep(prev => prev + 1);
            }, steps[currentStep].duration);

            return () => clearTimeout(timer);
        } else if (currentStep === steps.length - 1) {
            // Wait a bit on the final step before calling onComplete
            const timer = setTimeout(() => {
                onComplete();
            }, steps[currentStep].duration);

            return () => clearTimeout(timer);
        }
    }, [currentStep, steps, onComplete]);

    return (
        <div css={tw`bg-neutral-700 rounded-lg p-6`}>
            <div css={tw`flex items-center justify-between mb-6`}>
                <h2 css={tw`text-2xl font-bold text-neutral-100`}>Installing Modpack</h2>
                {currentStep < steps.length - 1 && <Spinner size="small" />}
            </div>

            <div css={tw`space-y-4`}>
                {steps.map((step, index) => (
                    <div
                        key={index}
                        css={[
                            tw`flex items-center gap-3 p-3 rounded transition-all duration-300`,
                            index < currentStep && tw`bg-green-900 bg-opacity-20`,
                            index === currentStep && tw`bg-blue-900 bg-opacity-20`,
                            index > currentStep && tw`bg-neutral-800 opacity-50`,
                        ]}
                    >
                        <div
                            css={[
                                tw`w-8 h-8 rounded-full flex items-center justify-center`,
                                index < currentStep && tw`bg-green-600`,
                                index === currentStep && tw`bg-blue-600`,
                                index > currentStep && tw`bg-neutral-600`,
                            ]}
                        >
                            {index < currentStep ? (
                                <FontAwesomeIcon icon={faCheckCircle} css={tw`text-white`} />
                            ) : index === currentStep ? (
                                <Spinner size="tiny" />
                            ) : (
                                <FontAwesomeIcon icon={step.icon} css={tw`text-neutral-400`} />
                            )}
                        </div>
                        <div css={tw`flex-1`}>
                            <p
                                css={[
                                    tw`text-sm font-medium`,
                                    index <= currentStep ? tw`text-neutral-100` : tw`text-neutral-400`,
                                ]}
                            >
                                {step.label}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div css={tw`mt-6`}>
                <div css={tw`bg-neutral-800 rounded-full h-2 overflow-hidden`}>
                    <div
                        css={tw`bg-blue-500 h-full transition-all duration-500 ease-out`}
                        style={{ width: `${((currentStep + 1) / steps.length) * 100}%` }}
                    />
                </div>
                <p css={tw`text-xs text-neutral-400 text-center mt-2`}>
                    Step {currentStep + 1} of {steps.length}
                </p>
            </div>

            <div css={tw`mt-6 p-4 bg-neutral-800 rounded-lg`}>
                <p css={tw`text-xs text-neutral-400 mb-2`}>Installation Notes:</p>
                <ul css={tw`text-xs text-neutral-300 space-y-1 list-disc list-inside`}>
                    <li>All mods are being downloaded to your server's /mods directory</li>
                    <li>This process may take several minutes depending on modpack size</li>
                    <li>Do not close this window or navigate away</li>
                </ul>
            </div>
        </div>
    );
};
