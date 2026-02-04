import { faBalanceScale, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import { useFormikContext } from 'formik';
import tw from 'twin.macro';
import { useState } from 'react';
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/outline';

import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import FormikSwitch from '@/elements/FormikSwitch';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

export default () => {
    const { isSubmitting } = useFormikContext();
    const [advancedExpanded, setAdvancedExpanded] = useState(false);

    return (
        <AdminBox icon={faBalanceScale} title={'Resources'} isLoading={isSubmitting}>
            {/* Basic Resources - Always Visible */}
            <div css={tw`mb-6`}>
                <h3 css={tw`text-lg font-semibold text-neutral-100 mb-4 flex items-center`}>✅ Basic Resources</h3>
                <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4 lg:gap-6`}>
                    <Field
                        id={'limits.cpu'}
                        name={'limits.cpu'}
                        label={'CPU Limit'}
                        type={'text'}
                        description={
                            'Each thread on the system is considered to be 100%. Setting this value to 0 will allow the server to use CPU time without restriction.'
                        }
                    />
                    <Field
                        id={'limits.memory'}
                        name={'limits.memory'}
                        label={'Memory Limit'}
                        type={'number'}
                        description={
                            'The maximum amount of memory allowed for this container. Setting this to 0 will allow unlimited memory in a container.'
                        }
                    />
                    <Field
                        id={'limits.disk'}
                        name={'limits.disk'}
                        label={'Disk Limit'}
                        type={'number'}
                        description={
                            'This server will not be allowed to boot if it is using more than this amount of space. If a server goes over this limit while running it will be safely stopped and locked until enough space is available. Set to 0 to allow unlimited disk usage.'
                        }
                    />
                    <div css={tw`xl:col-span-1 bg-neutral-800 border border-neutral-900 shadow-inner p-4 rounded`}>
                        <FormikSwitch
                            name={'limits.oomKiller'}
                            label={'Out of Memory Killer'}
                            description={
                                'Enabling the Out of Memory Killer may cause server processes to exit unexpectedly.'
                            }
                        />
                        <div css={tw`mt-2 text-xs text-yellow-400 flex items-center`}>
                            <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mr-1.5`} />
                            <span>Use with caution</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Advanced Resources - Collapsible */}
            <div css={tw`border-t border-neutral-700 pt-4`}>
                <button
                    type="button"
                    onClick={() => setAdvancedExpanded(!advancedExpanded)}
                    css={tw`w-full flex items-center justify-between text-lg font-semibold text-neutral-100 mb-4 hover:text-neutral-50 transition-colors`}
                >
                    <span css={tw`flex items-center`}>⚠️ Advanced Resources</span>
                    {advancedExpanded ? <ChevronUpIcon css={tw`w-5 h-5`} /> : <ChevronDownIcon css={tw`w-5 h-5`} />}
                </button>

                {advancedExpanded && (
                    <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4 lg:gap-6`}>
                        <Field
                            id={'limits.threads'}
                            name={'limits.threads'}
                            label={'CPU Pinning'}
                            type={'text'}
                            description={
                                'Advanced: Enter the specific CPU cores that this server can run on, or leave blank to allow all cores. This can be a single number, and or a comma separated list, and or a dashed range. Example: 0, 0-1,3, or 0,1,3,4.  It is recommended to leave this value blank and let the CPU handle balancing the load.'
                            }
                        />
                        <Field id={'limits.swap'} name={'limits.swap'} label={'Swap Limit'} type={'number'} />
                        <Field
                            id={'limits.io'}
                            name={'limits.io'}
                            label={'Block IO Proportion'}
                            type={'number'}
                            description={
                                'Advanced: The IO performance of this server relative to other running containers on the system. Value should be between 10 and 1000.'
                            }
                        />
                    </div>
                )}
            </div>
        </AdminBox>
    );
};
