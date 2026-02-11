import { WebhookEvent } from '@/api/routes/admin/webhooks';
import { CheckCircleIcon, XCircleIcon, ViewGridIcon } from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';

interface Props {
    events: WebhookEvent[];
}

export default ({ events }: Props) => {
    const { colors } = useStoreState(s => s.theme.data!);
    const webhookUrl = useStoreState(state => state.everest.data!.webhooks.url);

    const totalEvents = events.length;
    const enabledEvents = events.filter(e => e.enabled).length;
    const disabledEvents = totalEvents - enabledEvents;
    const enabledPercentage = totalEvents > 0 ? Math.round((enabledEvents / totalEvents) * 100) : 0;

    // Group by category
    const categories = new Set(events.map(e => e.key.split(':')[1]));
    const categoryCount = categories.size;

    return (
        <div className={'mb-6 grid gap-4 md:grid-cols-4'}>
            {/* Total Events */}
            <div className={'rounded-lg border border-neutral-700 bg-neutral-800 p-4'}>
                <div className={'flex items-center justify-between'}>
                    <div>
                        <p className={'text-sm text-neutral-400'}>Total Events</p>
                        <p className={'mt-1 text-2xl font-semibold text-neutral-100'}>{totalEvents}</p>
                    </div>
                    <div
                        className={'rounded-lg p-3'}
                        style={{ backgroundColor: `${colors.primary}20`, color: colors.primary }}
                    >
                        <ViewGridIcon className={'h-6 w-6'} />
                    </div>
                </div>
            </div>

            {/* Enabled Events */}
            <div className={'rounded-lg border border-green-500/30 bg-green-500/5 p-4'}>
                <div className={'flex items-center justify-between'}>
                    <div>
                        <p className={'text-sm text-neutral-400'}>Enabled</p>
                        <p className={'mt-1 text-2xl font-semibold text-green-400'}>{enabledEvents}</p>
                        <p className={'text-xs text-neutral-500'}>{enabledPercentage}% active</p>
                    </div>
                    <div className={'rounded-lg bg-green-500/20 p-3 text-green-400'}>
                        <CheckCircleIcon className={'h-6 w-6'} />
                    </div>
                </div>
            </div>

            {/* Disabled Events */}
            <div className={'rounded-lg border border-neutral-700 bg-neutral-800 p-4'}>
                <div className={'flex items-center justify-between'}>
                    <div>
                        <p className={'text-sm text-neutral-400'}>Disabled</p>
                        <p className={'mt-1 text-2xl font-semibold text-neutral-300'}>{disabledEvents}</p>
                        <p className={'text-xs text-neutral-500'}>{100 - enabledPercentage}% inactive</p>
                    </div>
                    <div className={'rounded-lg bg-neutral-700 p-3 text-neutral-400'}>
                        <XCircleIcon className={'h-6 w-6'} />
                    </div>
                </div>
            </div>

            {/* Categories */}
            <div className={'rounded-lg border border-neutral-700 bg-neutral-800 p-4'}>
                <div className={'flex items-center justify-between'}>
                    <div>
                        <p className={'text-sm text-neutral-400'}>Categories</p>
                        <p className={'mt-1 text-2xl font-semibold text-neutral-100'}>{categoryCount}</p>
                        <p className={'text-xs text-neutral-500'}>{webhookUrl ? 'URL configured' : 'No URL set'}</p>
                    </div>
                    <div
                        className={'rounded-lg p-3'}
                        style={{ backgroundColor: `${colors.secondary}40`, color: colors.secondary }}
                    >
                        <svg className={'h-6 w-6'} fill={'none'} viewBox={'0 0 24 24'} stroke={'currentColor'}>
                            <path
                                strokeLinecap={'round'}
                                strokeLinejoin={'round'}
                                strokeWidth={2}
                                d={
                                    'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'
                                }
                            />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    );
};
