import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faDownload, faFileArchive, faFolderOpen } from '@fortawesome/free-solid-svg-icons';

interface Props {
    isDownloading: boolean;
    onComplete: () => void;
}

export default ({ isDownloading, onComplete }: Props) => {
    const [currentStep, setCurrentStep] = useState(0);
    const [steps] = useState([
        { icon: faDownload, label: 'Downloading modpack archive...', duration: 2000 },
        { icon: faFileArchive, label: 'Extracting modpack files...', duration: 1500 },
        { icon: faFolderOpen, label: 'Installing mods and server files...', duration: -1 }, // -1 means wait for isDownloading to become false
        { icon: faCheckCircle, label: 'Installation complete!', duration: 1000 },
    ]);

    useEffect(() => {
        if (currentStep === 0 || currentStep === 1) {
            // First two steps use timers
            const timer = setTimeout(() => {
                setCurrentStep(prev => prev + 1);
            }, steps[currentStep].duration);

            return () => clearTimeout(timer);
        } else if (currentStep === 2) {
            // Step 3: Wait for actual download to complete
            if (!isDownloading) {
                // Download finished, move to final step
                setCurrentStep(3);
            }
        } else if (currentStep === 3) {
            // Final step: wait a bit then call onComplete
            const timer = setTimeout(() => {
                onComplete();
            }, steps[currentStep].duration);

            return () => clearTimeout(timer);
        }
    }, [currentStep, isDownloading, steps, onComplete]);

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
                    <li>All server files and mods are being installed to your server</li>
                    <li>This process may take several minutes depending on modpack size</li>
                    <li>The installation phase downloads each mod individually from CurseForge</li>
                    <li>Do not close this window or navigate away during installation</li>
                </ul>
            </div>
        </div>
    );
};
