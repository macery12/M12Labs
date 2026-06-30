import { lazy } from 'react';
import { route, type RouteDef } from './registry';

const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const CheckpointPage = lazy(() => import('@/pages/auth/CheckpointPage'));

// Auth area (/v2/auth/*). Seeded from V1_UI_Map §3.1.
export const authRoutes: RouteDef[] = [
    route('login', { name: 'Login', element: LoginPage, end: true }),
    route('login/checkpoint', { name: '2FA Checkpoint', element: CheckpointPage }),
    route('register', { name: 'Register', condition: f => f.auth.registration.enabled }),
    route('password', { name: 'Forgot Password' }),
    route('password/reset/:token', {}),
    route('discord/link-choice', { condition: f => f.auth.modules.discord.enabled }),
    route('discord/register', { condition: f => f.auth.modules.discord.enabled }),
];
