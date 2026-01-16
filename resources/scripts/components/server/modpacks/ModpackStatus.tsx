import { useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { Button } from '@/elements/button';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';
import { 
    clearInstalledModpack, 
    verifyModpackFiles, 
    type InstalledModpackInfo,
    type ModpackVerificationResult 
} from '@/api/routes/server/modpacks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { 
    faCheckCircle, 
    faTimesCircle, 
    faExclamationTriangle,
    faSync,
    faExchangeAlt
} from '@fortawesome/free-solid-svg-icons';

interface Props {
    installedModpack: InstalledModpackInfo;
    onSwapModpack: () => void;
}

export default ({ installedModpack, onSwapModpack }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addFlash, addError } = useFlash();

    const [verifying, setVerifying] = useState(false);
    const [verificationResult, setVerificationResult] = useState<ModpackVerificationResult | null>(null);
    const [swapping, setSwapping] = useState(false);

    const handleVerify = () => {
        setVerifying(true);
        verifyModpackFiles(uuid)
            .then(result => {
                setVerificationResult(result);
                if (result.missing === 0 && result.failed_during_install.length === 0) {
                    addFlash({
                        key: 'modpacks',
                        type: 'success',
                        message: 'All modpack files verified successfully!',
                    });
                } else if (result.missing > 0) {
                    addFlash({
                        key: 'modpacks',
                        type: 'warning',
                        message: `${result.missing} file(s) are missing from the modpack.`,
                    });
                }
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => setVerifying(false));
    };

    const handleSwapModpack = () => {
        setSwapping(true);
        clearInstalledModpack(uuid)
            .then(() => {
                addFlash({
                    key: 'modpacks',
                    type: 'success',
                    message: 'Modpack information cleared. You can now install a new modpack.',
                });
                onSwapModpack();
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => setSwapping(false));
    };

    const files = installedModpack.files;
    const totalMods = files?.total || 0;
    const downloadedCount = files?.downloaded?.length || 0;
    const failedCount = files?.failed?.length || 0;
    const successRate = totalMods > 0 ? ((downloadedCount / totalMods) * 100).toFixed(1) : '0';

    return (
        <div css={tw`bg-neutral-700 rounded-lg p-6 mb-6`}>
            <div css={tw`flex items-center justify-between mb-6`}>
                <div>
                    <h2 css={tw`text-2xl font-bold text-neutral-100`}>
                        {installedModpack.name || 'Unknown Modpack'}
                    </h2>
                    <p css={tw`text-neutral-400 text-sm mt-1`}>
                        Version: {installedModpack.version || 'Unknown'}
                    </p>
                </div>
                <Button
                    size={Button.Sizes.Small}
                    onClick={handleSwapModpack}
                    disabled={swapping}
                    css={tw`flex items-center gap-2`}
                >
                    {swapping ? (
                        <>
                            <Spinner size={'small'} />
                            <span>Swapping...</span>
                        </>
                    ) : (
                        <>
                            <FontAwesomeIcon icon={faExchangeAlt} />
                            <span>Swap Modpack</span>
                        </>
                    )}
                </Button>
            </div>

            {/* Installation Statistics */}
            <div css={tw`grid grid-cols-1 md:grid-cols-3 gap-4 mb-6`}>
                <div css={tw`bg-neutral-800 rounded-lg p-4`}>
                    <div css={tw`flex items-center justify-between`}>
                        <div>
                            <p css={tw`text-neutral-400 text-sm`}>Total Mods</p>
                            <p css={tw`text-3xl font-bold text-neutral-100 mt-1`}>{totalMods}</p>
                        </div>
                        <FontAwesomeIcon icon={faCheckCircle} css={tw`text-4xl text-neutral-500`} />
                    </div>
                </div>

                <div css={tw`bg-neutral-800 rounded-lg p-4`}>
                    <div css={tw`flex items-center justify-between`}>
                        <div>
                            <p css={tw`text-neutral-400 text-sm`}>Downloaded</p>
                            <p css={tw`text-3xl font-bold text-green-400 mt-1`}>{downloadedCount}</p>
                        </div>
                        <FontAwesomeIcon icon={faCheckCircle} css={tw`text-4xl text-green-500`} />
                    </div>
                </div>

                <div css={tw`bg-neutral-800 rounded-lg p-4`}>
                    <div css={tw`flex items-center justify-between`}>
                        <div>
                            <p css={tw`text-neutral-400 text-sm`}>Failed</p>
                            <p css={tw`text-3xl font-bold text-red-400 mt-1`}>{failedCount}</p>
                        </div>
                        <FontAwesomeIcon icon={faTimesCircle} css={tw`text-4xl text-red-500`} />
                    </div>
                </div>
            </div>

            {/* Progress Bar */}
            <div css={tw`mb-6`}>
                <div css={tw`flex justify-between items-center mb-2`}>
                    <span css={tw`text-neutral-300 text-sm font-medium`}>Installation Progress</span>
                    <span css={tw`text-neutral-300 text-sm font-medium`}>{successRate}%</span>
                </div>
                <div css={tw`w-full bg-neutral-800 rounded-full h-4 overflow-hidden`}>
                    <div
                        css={[
                            tw`h-full transition-all duration-300`,
                            failedCount > 0 ? tw`bg-yellow-500` : tw`bg-green-500`,
                        ]}
                        style={{ width: `${successRate}%` }}
                    />
                </div>
            </div>

            {/* Verification Section */}
            <div css={tw`border-t border-neutral-600 pt-6`}>
                <div css={tw`flex items-center justify-between mb-4`}>
                    <div>
                        <h3 css={tw`text-lg font-semibold text-neutral-100`}>File Verification</h3>
                        <p css={tw`text-neutral-400 text-sm mt-1`}>
                            Verify that all modpack files are present in the /mods directory
                        </p>
                    </div>
                    <Button
                        size={Button.Sizes.Small}
                        onClick={handleVerify}
                        disabled={verifying}
                        css={tw`flex items-center gap-2`}
                    >
                        {verifying ? (
                            <>
                                <Spinner size={'small'} />
                                <span>Verifying...</span>
                            </>
                        ) : (
                            <>
                                <FontAwesomeIcon icon={faSync} />
                                <span>Verify Files</span>
                            </>
                        )}
                    </Button>
                </div>

                {verificationResult && (
                    <div css={tw`bg-neutral-800 rounded-lg p-4`}>
                        <div css={tw`grid grid-cols-1 md:grid-cols-3 gap-4 mb-4`}>
                            <div>
                                <p css={tw`text-neutral-400 text-xs uppercase`}>Expected</p>
                                <p css={tw`text-xl font-bold text-neutral-100`}>
                                    {verificationResult.total_expected}
                                </p>
                            </div>
                            <div>
                                <p css={tw`text-neutral-400 text-xs uppercase`}>Verified</p>
                                <p css={tw`text-xl font-bold text-green-400`}>
                                    {verificationResult.verified}
                                </p>
                            </div>
                            <div>
                                <p css={tw`text-neutral-400 text-xs uppercase`}>Missing</p>
                                <p css={tw`text-xl font-bold text-red-400`}>
                                    {verificationResult.missing}
                                </p>
                            </div>
                        </div>

                        {verificationResult.missing > 0 && (
                            <div css={tw`bg-red-900 bg-opacity-20 border border-red-700 rounded-lg p-4 mt-4`}>
                                <div css={tw`flex items-start gap-3`}>
                                    <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-red-400 mt-1`} />
                                    <div css={tw`flex-1`}>
                                        <p css={tw`text-red-400 font-semibold mb-2`}>
                                            Missing Files ({verificationResult.missing})
                                        </p>
                                        <div css={tw`max-h-40 overflow-y-auto`}>
                                            {verificationResult.missing_files.map((file, idx) => (
                                                <p key={idx} css={tw`text-red-300 text-sm font-mono`}>
                                                    {file}
                                                </p>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {verificationResult.failed_during_install.length > 0 && (
                            <div css={tw`bg-yellow-900 bg-opacity-20 border border-yellow-700 rounded-lg p-4 mt-4`}>
                                <div css={tw`flex items-start gap-3`}>
                                    <FontAwesomeIcon icon={faExclamationTriangle} css={tw`text-yellow-400 mt-1`} />
                                    <div css={tw`flex-1`}>
                                        <p css={tw`text-yellow-400 font-semibold mb-2`}>
                                            Failed During Installation ({verificationResult.failed_during_install.length})
                                        </p>
                                        <p css={tw`text-yellow-300 text-sm`}>
                                            These mods failed to download during installation. You may need to reinstall the modpack.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {verificationResult.missing === 0 && verificationResult.failed_during_install.length === 0 && (
                            <div css={tw`bg-green-900 bg-opacity-20 border border-green-700 rounded-lg p-4 mt-4`}>
                                <div css={tw`flex items-center gap-3`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`text-green-400`} />
                                    <p css={tw`text-green-400 font-semibold`}>
                                        All files verified successfully!
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};
