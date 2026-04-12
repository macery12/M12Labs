import { lazy } from 'react';
import '@/assets/tailwind.css';
import { store } from '@/state';
import { SiteTheme } from '@/state/theme';
import { StoreProvider } from 'easy-peasy';
import { AdminContext } from '@/state/admin';
import { ServerContext } from '@/state/server';
import { SiteSettings } from '@/state/settings';
import Spinner from '@/elements/Spinner';
import ProgressBar from '@/elements/ProgressBar';
import GlobalStylesheet from '@/assets/css/GlobalStylesheet';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import AuthenticatedRoute from '@/elements/AuthenticatedRoute';
import { NotFound } from '@/elements/ScreenBlock';
import { EverestSettings } from '@/state/everest';
import Onboarding from '@account/Onboarding';
import SpeedDial from '@/elements/SpeedDial';
import SetupContainer from './admin/setup/SetupContainer';

const AdminRouter = lazy(() => import('@/routers/AdminRouter'));
const AuthenticationRouter = lazy(() => import('@/routers/AuthenticationRouter'));
const DashboardRouter = lazy(() => import('@/routers/DashboardRouter'));
const ServerRouter = lazy(() => import('@/routers/ServerRouter'));

interface ExtendedWindow extends Window {
    SiteConfiguration?: SiteSettings;
    ThemeConfiguration?: SiteTheme;
    EverestConfiguration?: EverestSettings & { email?: { enabled?: boolean } };
    PterodactylUser?: {
        uuid: string;
        username: string;
        email: string;
        root_admin: boolean;
        use_totp: boolean;
        language: string;
        avatar_url: string;
        admin_role_name: string;
        admin_role_id?: number;
        state: string;
        email_verified?: boolean;
        email_verified_at?: string | null;
        updated_at: string;
        created_at: string;
    };
}

function App() {
    const { PterodactylUser, SiteConfiguration, EverestConfiguration, ThemeConfiguration } = window as ExtendedWindow;

    if (PterodactylUser && !store.getState().user.data) {
        store.getActions().user.setUserData({
            uuid: PterodactylUser.uuid,
            username: PterodactylUser.username,
            email: PterodactylUser.email,
            language: PterodactylUser.language,
            rootAdmin: PterodactylUser.root_admin,
            avatarURL: PterodactylUser.avatar_url,
            roleName: PterodactylUser.admin_role_name,
            admin_role_id: PterodactylUser.admin_role_id,
            state: PterodactylUser.state,
            useTotp: PterodactylUser.use_totp,
            emailVerified: Boolean(PterodactylUser.email_verified),
            emailVerifiedAt: PterodactylUser.email_verified_at ? new Date(PterodactylUser.email_verified_at) : undefined,
            createdAt: new Date(PterodactylUser.created_at),
            updatedAt: new Date(PterodactylUser.updated_at),
        });
    }

    if (!store.getState().settings.data) {
        store.getActions().settings.setSettings(SiteConfiguration!);
    }

    if (!store.getState().theme.data) {
        store.getActions().theme.setTheme(ThemeConfiguration!);
    }

    if (!store.getState().everest.data && EverestConfiguration) {
        store.getActions().everest.setEverest(EverestConfiguration);
    }

    if (PterodactylUser?.state === 'suspended') {
        return (
            <div style={{ color: 'white', fontWeight: 'bold', marginTop: '10px', marginLeft: '10px' }}>
                Your account has been suspended and blocked by an administrator.
            </div>
        );
    }

    if (PterodactylUser?.state === 'pending') {
        return (
            <div
                style={{
                    color: 'white',
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100vh',
                    gap: '12px',
                    textAlign: 'center',
                    padding: '24px',
                }}
            >
                <svg
                    xmlns='http://www.w3.org/2000/svg'
                    style={{ width: 48, height: 48, color: '#a78bfa' }}
                    fill='none'
                    viewBox='0 0 24 24'
                    stroke='currentColor'
                >
                    <path
                        strokeLinecap='round'
                        strokeLinejoin='round'
                        strokeWidth={1.5}
                        d='M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'
                    />
                </svg>
                <h1 style={{ fontSize: '1.5rem', fontWeight: 700 }}>Account Pending Approval</h1>
                <p style={{ color: '#9ca3af', maxWidth: 420 }}>
                    Your account is awaiting review by an administrator. You will receive access once your registration
                    has been approved. Please check back later.
                </p>
            </div>
        );
    }

    const hasAdminRole: boolean = (PterodactylUser?.root_admin || Boolean(PterodactylUser?.admin_role_id)) ?? false;

    return (
        <>
            <GlobalStylesheet />
            <StoreProvider store={store}>
                <ProgressBar />
                {PterodactylUser?.root_admin && !SiteConfiguration?.setup ? (
                    <SetupContainer />
                ) : (
                    <>
                        {' '}
                        {PterodactylUser?.username.startsWith('null_user_') &&
                        EverestConfiguration?.auth.modules.onboarding.enabled ? (
                            <Onboarding />
                        ) : (
                            <div className="mx-auto w-auto">
                                <BrowserRouter>
                                    <Routes>
                                        <Route
                                            path="/auth/*"
                                            element={
                                                <Spinner.Suspense>
                                                    <AuthenticationRouter />
                                                </Spinner.Suspense>
                                            }
                                        />

                                        <Route
                                            path="/server/:id/*"
                                            element={
                                                <AuthenticatedRoute>
                                                    <Spinner.Suspense>
                                                        <ServerContext.Provider>
                                                            {hasAdminRole && <SpeedDial />}
                                                            <ServerRouter />
                                                        </ServerContext.Provider>
                                                    </Spinner.Suspense>
                                                </AuthenticatedRoute>
                                            }
                                        />

                                        <Route
                                            path="/admin/*"
                                            element={
                                                <Spinner.Suspense>
                                                    <AdminContext.Provider>
                                                        <AdminRouter />
                                                    </AdminContext.Provider>
                                                </Spinner.Suspense>
                                            }
                                        />

                                        <Route
                                            path="/*"
                                            element={
                                                <AuthenticatedRoute>
                                                    <Spinner.Suspense>
                                                        {hasAdminRole && <SpeedDial />}
                                                        <DashboardRouter />
                                                    </Spinner.Suspense>
                                                </AuthenticatedRoute>
                                            }
                                        />

                                        <Route path="*" element={<NotFound />} />
                                    </Routes>
                                </BrowserRouter>
                            </div>
                        )}
                    </>
                )}
            </StoreProvider>
        </>
    );
}

export { App };
