<?php

namespace Everest\Http\ViewComposers;

use Illuminate\View\View;

class ThemeComposer
{
    /**
     * Provide access to the asset service in the views.
     */
    public function compose(View $view): void
    {
        $colors = [
            'primary' => config('colors.primary') ?? config('modules.theme.colors.primary'),
            'secondary' => config('colors.secondary') ?? config('modules.theme.colors.secondary'),

            'background' => config('colors.background') ?? config('modules.theme.colors.background'),
            'headers' => config('colors.headers') ?? config('modules.theme.colors.headers'),
            'sidebar' => config('colors.sidebar') ?? config('modules.theme.colors.sidebar'),
        ];

        $tokens = [
            'base' => [
                'background' => $colors['background'],
                'foreground' => '#f8fafc',
                'muted' => '#a1a1aa',
                'border' => '#27272a',
            ],
            'surfaces' => [
                'panel' => $colors['secondary'],
                'raised' => '#1f1f22',
                'header' => $colors['headers'],
                'overlay' => 'rgba(0,0,0,0.65)',
            ],
            'navigation' => [
                'sidebar' => $colors['sidebar'],
                'sidebarActive' => $colors['primary'],
                'navbar' => $colors['headers'],
                'navbarBorder' => $colors['primary'],
            ],
            'text' => [
                'primary' => '#f8fafc',
                'secondary' => '#e5e7eb',
                'muted' => '#a1a1aa',
                'inverse' => '#0f172a',
                'onAccent' => '#0b0f12',
            ],
            'status' => [
                'success' => '#22c55e',
                'warning' => '#f59e0b',
                'danger' => '#ef4444',
                'info' => '#38bdf8',
            ],
            'inputs' => [
                'background' => $colors['background'],
                'surface' => $colors['headers'],
                'border' => '#27272a',
                'focus' => $colors['primary'],
                'text' => '#f8fafc',
                'placeholder' => '#9ca3af',
            ],
            'interactive' => [
                'accent' => $colors['primary'],
                'accentMuted' => '#14532d',
                'accentHover' => '#22c55e',
                'selection' => 'rgba(34,197,94,0.25)',
            ],
            'borders' => [
                'subtle' => '#1f2937',
                'strong' => '#374151',
            ],
        ];

        $view->with('themeConfiguration', [
            'colors' => $colors,
            'tokens' => $tokens,
        ]);
    }
}
