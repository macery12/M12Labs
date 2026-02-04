import { faConciergeBell } from '@fortawesome/free-solid-svg-icons';
import { useFormikContext } from 'formik';
import tw from 'twin.macro';
import { InformationCircleIcon } from '@heroicons/react/outline';

import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import Tooltip from '@/elements/tooltip/Tooltip';

export default () => {
    const { isSubmitting } = useFormikContext();

    return (
        <AdminBox icon={faConciergeBell} title={'Feature Limits'} isLoading={isSubmitting}>
            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                <div>
                    <div css={tw`flex items-center mb-2`}>
                        <label htmlFor="featureLimits.allocations" css={tw`text-sm font-medium text-neutral-200`}>
                            Allocations
                        </label>
                        <Tooltip
                            content="The total number of allocations a user is allowed to create for this server."
                            placement="top"
                        >
                            <InformationCircleIcon css={tw`w-4 h-4 ml-1.5 text-neutral-400 cursor-help`} />
                        </Tooltip>
                    </div>
                    <Field
                        id={'featureLimits.allocations'}
                        name={'featureLimits.allocations'}
                        type={'number'}
                        css={tw`mb-0`}
                    />
                </div>

                <div>
                    <div css={tw`flex items-center mb-2`}>
                        <label htmlFor="featureLimits.backups" css={tw`text-sm font-medium text-neutral-200`}>
                            Backups
                        </label>
                        <Tooltip
                            content="The total number of backups that can be created for this server."
                            placement="top"
                        >
                            <InformationCircleIcon css={tw`w-4 h-4 ml-1.5 text-neutral-400 cursor-help`} />
                        </Tooltip>
                    </div>
                    <Field id={'featureLimits.backups'} name={'featureLimits.backups'} type={'number'} css={tw`mb-0`} />
                </div>

                <div>
                    <div css={tw`flex items-center mb-2`}>
                        <label htmlFor="featureLimits.databases" css={tw`text-sm font-medium text-neutral-200`}>
                            Databases
                        </label>
                        <Tooltip
                            content="The total number of databases a user is allowed to create for this server."
                            placement="top"
                        >
                            <InformationCircleIcon css={tw`w-4 h-4 ml-1.5 text-neutral-400 cursor-help`} />
                        </Tooltip>
                    </div>
                    <Field
                        id={'featureLimits.databases'}
                        name={'featureLimits.databases'}
                        type={'number'}
                        css={tw`mb-0`}
                    />
                </div>

                <div>
                    <div css={tw`flex items-center mb-2`}>
                        <label htmlFor="featureLimits.subusers" css={tw`text-sm font-medium text-neutral-200`}>
                            Subusers
                        </label>
                        <Tooltip
                            content="The total number of subusers that can be added to this server."
                            placement="top"
                        >
                            <InformationCircleIcon css={tw`w-4 h-4 ml-1.5 text-neutral-400 cursor-help`} />
                        </Tooltip>
                    </div>
                    <Field
                        id={'featureLimits.subusers'}
                        name={'featureLimits.subusers'}
                        type={'number'}
                        css={tw`mb-0`}
                    />
                </div>
            </div>
        </AdminBox>
    );
};
