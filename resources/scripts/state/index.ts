import { createStore } from 'easy-peasy';
import user, { UserStore } from '@/state/user';
import theme, { ThemeStore } from '@/state/theme';
import everest, { EverestStore } from '@/state/everest';
import settings, { SettingsStore } from '@/state/settings';
import progress, { ProgressStore } from '@/state/progress';
import permissions, { GloablPermissionsStore } from '@/state/server/permissions';

export interface ApplicationStore {
    permissions: GloablPermissionsStore;
    user: UserStore;
    settings: SettingsStore;
    progress: ProgressStore;
    everest: EverestStore;
    theme: ThemeStore;
}

const state: ApplicationStore = {
    permissions,
    user,
    settings,
    progress,
    everest,
    theme,
};

export const store = createStore(state);
