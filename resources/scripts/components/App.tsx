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
import http from '@/api/http';

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
        discord_linked?: boolean;
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
            discordLinked: Boolean(PterodactylUser.discord_linked),
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
        const handleLogout = () => {
            http.post('/auth/logout').finally(() => {
                window.location.href = '/auth/login';
            });
        };

        return (
            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    minHeight: '100vh',
                    backgroundColor: '#111',
                    color: 'white',
                    textAlign: 'center',
                    padding: '2rem',
                }}
            >
                <h1 style={{ fontSize: '2rem', fontWeight: 'bold', marginBottom: '1rem' }}>Account Suspended</h1>
                <p style={{ color: '#aaa', marginBottom: '2rem', maxWidth: '400px' }}>
                    Your account has been suspended and blocked by an administrator.
                </p>
                <button
                    onClick={handleLogout}
                    style={{
                        backgroundColor: '#e53e3e',
                        color: 'white',
                        border: 'none',
                        borderRadius: '0.375rem',
                        padding: '0.625rem 1.5rem',
                        fontSize: '1rem',
                        fontWeight: '600',
                        cursor: 'pointer',
                    }}
                >
                    Logout
                </button>
            </div>
        );
    }

    if (PterodactylUser?.state === 'pending') {
        window.alert('Your registration is awaiting approval by an administrator. You will be notified once your account is approved.');
        http.post('/auth/logout').finally(() => {
            window.location.href = '/auth/login';
        });
        return null;
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
