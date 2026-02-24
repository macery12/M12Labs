import type { JSX } from 'react';
import type { ConsoleWorkspaceLayout, ConsoleWorkspaceModuleId } from '@definitions/server';

export interface WorkspaceModuleDefinition {
    id: ConsoleWorkspaceModuleId;
    title: string;
    description: string;
    minW?: number;
    minH?: number;
    component: JSX.Element | null;
}

export type WorkspaceLayoutState = ConsoleWorkspaceLayout;
