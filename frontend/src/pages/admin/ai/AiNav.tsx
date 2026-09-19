import {
    Activity,
    Bot,
    Gauge,
    ListOrdered,
    Plug,
    ShieldCheck,
    SlidersHorizontal,
    Wallet,
    Wrench,
} from 'lucide-react';
import { td } from '@/i18n/messages';
import { SectionNavigation, type SectionNavigationGroup } from '@/extensions-sdk';

// Absolute paths — relative `to` would compound against the active route.
const BASE = '/admin/ai';

function groups(): SectionNavigationGroup[] {
    return [
        {
            id: 'connection',
            label: td('admin.ai.nav.groups.connection'),
            items: [
                { to: BASE, end: true, icon: Activity, label: td('admin.ai.nav.overview') },
                { to: `${BASE}/provider`, icon: Plug, label: td('admin.ai.nav.provider') },
                { to: `${BASE}/generation`, icon: SlidersHorizontal, label: td('admin.ai.nav.generation') },
            ],
        },
        {
            id: 'assistants',
            label: td('admin.ai.nav.groups.assistants'),
            items: [
                { to: `${BASE}/agent`, icon: Bot, label: td('admin.ai.nav.agent') },
                { to: `${BASE}/tools`, icon: Wrench, label: td('admin.ai.nav.tools') },
                { to: `${BASE}/privacy`, icon: ShieldCheck, label: td('admin.ai.nav.privacy') },
            ],
        },
        {
            id: 'operations',
            label: td('admin.ai.nav.groups.operations'),
            items: [
                { to: `${BASE}/performance`, icon: Gauge, label: td('admin.ai.nav.performance') },
                { to: `${BASE}/limits`, icon: Wallet, label: td('admin.ai.nav.limits') },
                { to: `${BASE}/logs`, icon: ListOrdered, label: td('admin.ai.nav.logs') },
            ],
        },
    ];
}

// In-page secondary navigation for the AI section — a left rail on lg+, a
// horizontal scroll strip on small screens. Mirrors EmailNav.
//
// Every item is always shown, including ones whose settings the configured
// provider ignores. Those pages explain themselves instead; a rail that
// reshuffles when you change a dropdown is worse than a page that says why it
// is empty.
export function AiNav() {
    return <SectionNavigation groups={groups()} />;
}
