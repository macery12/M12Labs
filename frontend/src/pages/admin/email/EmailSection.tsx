import { m } from '@/i18n/messages';
import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { Spinner } from '@/components/ui/Spinner';
import { EmailNav } from './EmailNav';

const OverviewPage = lazy(() => import('./pages/OverviewPage'));
const SmtpPage = lazy(() => import('./pages/SmtpPage'));
const ResendPage = lazy(() => import('./pages/ResendPage'));
const TestingPage = lazy(() => import('./pages/TestingPage'));
const NotificationsPage = lazy(() => import('./pages/NotificationsPage'));
const ActivityPage = lazy(() => import('./pages/ActivityPage'));
const TemplatesPage = lazy(() => import('./pages/TemplatesPage'));

// Mounted at the admin `email/*` splat route. Owns the email configuration
// (split into Overview/SMTP/Resend/Testing rail items), notifications, and the
// activity log.
export default function EmailSection() {
    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                        {m['admin.email.title']()}
                    </h1>
                    <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['admin.email.subtitle']()}</p>
                </div>
            </header>

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
                <EmailNav />
                <div className="min-w-0 flex-1">
                    <Suspense fallback={<div className="flex justify-center py-16"><Spinner className="h-6 w-6" /></div>}>
                        <Routes>
                            <Route index element={<OverviewPage />} />
                            <Route path="smtp" element={<SmtpPage />} />
                            <Route path="resend" element={<ResendPage />} />
                            <Route path="testing" element={<TestingPage />} />
                            <Route path="notifications" element={<NotificationsPage />} />
                            <Route path="activity" element={<ActivityPage />} />
                            <Route path="templates" element={<TemplatesPage />} />
                        </Routes>
                    </Suspense>
                </div>
            </div>

        </div>
    );
}
