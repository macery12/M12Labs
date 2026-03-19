import { useMemo, useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { useStoreState } from '@/state/hooks';
import { faDesktop } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';

type Mode = 'dashboard' | 'form' | 'table' | 'auth' | 'mobile';
type Device = 'desktop' | 'tablet' | 'phone';

export default ({ reload }: { reload: boolean }) => {
    const { primary } = useStoreState(s => s.theme.data!.colors);
    const [mode, setMode] = useState<Mode>('dashboard');
    const [device, setDevice] = useState<Device>('desktop');

    const src = useMemo(() => {
        const map: Record<Mode, string> = {
            dashboard: '/',
            form: '/account',
            table: '/admin/users',
            auth: '/auth/login',
            mobile: '/',
        };
        return reload ? '/null' : map[mode] ?? '/';
    }, [mode, reload]);

    const deviceWidth = useMemo(() => {
        const map: Record<Device, string> = {
            desktop: '100%',
            tablet: '900px',
            phone: '420px',
        };
        return map[device];
    }, [device]);

    return (
        <AdminBox title={'Live Preview'} icon={faDesktop} className={'h-full'}>
            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2 text-xs text-neutral-400">
                        <span>Preview modes</span>
                        <div className="flex gap-1">
                            {(['dashboard', 'form', 'table', 'auth', 'mobile'] as Mode[]).map(item => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => setMode(item)}
                                    className={classNames(
                                        'rounded px-2.5 py-1 capitalize transition',
                                        mode === item
                                            ? 'bg-white/10 text-neutral-50'
                                            : 'bg-black/30 text-neutral-300 hover:bg-white/5',
                                    )}
                                >
                                    {item}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex gap-1 text-xs text-neutral-400">
                        {(['desktop', 'tablet', 'phone'] as Device[]).map(item => (
                            <button
                                key={item}
                                type="button"
                                onClick={() => setDevice(item)}
                                className={classNames(
                                    'rounded px-2.5 py-1 capitalize transition',
                                    device === item
                                        ? 'bg-white/10 text-neutral-50'
                                        : 'bg-black/30 text-neutral-300 hover:bg-white/5',
                                )}
                            >
                                {item}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="flex justify-center">
                    <div className="w-full overflow-hidden rounded-lg border-2 shadow-lg" style={{ borderColor: primary }}>
                        <div className="flex justify-center bg-black/20 px-3 py-2 text-xs text-neutral-400">
                            {mode.charAt(0).toUpperCase() + mode.slice(1)} · {device === 'phone' ? 'Mobile' : device}
                        </div>
                        <div className="flex justify-center bg-neutral-950 px-2 py-3">
                            <iframe
                                key={`${mode}-${device}-${reload ? 'reload' : 'live'}`}
                                src={src}
                                style={{ borderColor: primary, maxWidth: deviceWidth }}
                                className={'h-[60vh] w-full rounded-md border-2 transition duration-500'}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AdminBox>
    );
};
