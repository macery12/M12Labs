<!DOCTYPE html>
<html lang="en">
    <head>
        <title>{{ config('app.name', 'Everest') }}</title>

        @section('meta')
            <meta charset="utf-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            <meta name="robots" content="noindex">
            <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png">
            <link rel="icon" type="image/png" href="/favicons/favicon-32x32.png" sizes="32x32">
            <link rel="icon" type="image/png" href="/favicons/favicon-16x16.png" sizes="16x16">
            <link rel="manifest" href="/favicons/manifest.json">
            <link rel="mask-icon" href="/favicons/safari-pinned-tab.svg" color="#bc6e3c">
            <link rel="shortcut icon" href="/favicons/favicon.ico">
            <meta name="msapplication-config" content="/favicons/browserconfig.xml">
            <meta name="theme-color" content="#0e4688">
        @show

        @section('user-data')
            @if(!is_null(Auth::user()))
                <script>
                    window.PterodactylUser = {!! json_encode(Auth::user()->toReactObject()) !!};
                </script>
            @endif
            @if(!empty($siteConfiguration))
                <script>
                    window.SiteConfiguration = {!! json_encode($siteConfiguration) !!};
                </script>
            @endif
            @if(!empty($everestConfiguration))
                <script>
                    window.EverestConfiguration = {!! json_encode($everestConfiguration) !!};
                </script>
            @endif
            @if(!empty($themeConfiguration))
                <script>
                    window.ThemeConfiguration = {!! json_encode($themeConfiguration) !!};
                </script>
            @endif
            @php
                $flashMessages = [];
                $flashTypes = ['success', 'error', 'info', 'warning'];
                foreach($flashTypes as $type) {
                    if(session()->has($type)) {
                        $flashMessages[] = ['type' => $type, 'message' => session($type)];
                    }
                }
            @endphp
            @if(!empty($flashMessages))
                <script>
                    window.FlashMessages = {!! json_encode($flashMessages) !!};
                </script>
            @endif
        @show
        <style>
            @import url('//fonts.googleapis.com/css?family=Rubik:300,400,500&display=swap');
            @import url('//fonts.googleapis.com/css?family=IBM+Plex+Mono|IBM+Plex+Sans:500&display=swap');

            :root {
                --theme-base-background: {{ $themeConfiguration['tokens']['base']['background'] ?? $themeConfiguration['colors']['background'] }};
                --theme-base-foreground: {{ $themeConfiguration['tokens']['base']['foreground'] ?? '#f8fafc' }};
                --theme-base-muted: {{ $themeConfiguration['tokens']['base']['muted'] ?? '#a1a1aa' }};
                --theme-base-border: {{ $themeConfiguration['tokens']['base']['border'] ?? '#27272a' }};

                --theme-surfaces-panel: {{ $themeConfiguration['tokens']['surfaces']['panel'] ?? $themeConfiguration['colors']['secondary'] }};
                --theme-surfaces-raised: {{ $themeConfiguration['tokens']['surfaces']['raised'] ?? '#1f1f22' }};
                --theme-surfaces-header: {{ $themeConfiguration['tokens']['surfaces']['header'] ?? $themeConfiguration['colors']['headers'] }};
                --theme-surfaces-overlay: {{ $themeConfiguration['tokens']['surfaces']['overlay'] ?? 'rgba(0,0,0,0.65)' }};

                --theme-navigation-sidebar: {{ $themeConfiguration['tokens']['navigation']['sidebar'] ?? $themeConfiguration['colors']['sidebar'] }};
                --theme-navigation-sidebarActive: {{ $themeConfiguration['tokens']['navigation']['sidebarActive'] ?? $themeConfiguration['colors']['primary'] }};
                --theme-navigation-navbar: {{ $themeConfiguration['tokens']['navigation']['navbar'] ?? $themeConfiguration['colors']['headers'] }};
                --theme-navigation-navbarBorder: {{ $themeConfiguration['tokens']['navigation']['navbarBorder'] ?? $themeConfiguration['colors']['primary'] }};

                --theme-text-primary: {{ $themeConfiguration['tokens']['text']['primary'] ?? '#f8fafc' }};
                --theme-text-secondary: {{ $themeConfiguration['tokens']['text']['secondary'] ?? '#e5e7eb' }};
                --theme-text-muted: {{ $themeConfiguration['tokens']['text']['muted'] ?? '#a1a1aa' }};
                --theme-text-inverse: {{ $themeConfiguration['tokens']['text']['inverse'] ?? '#0f172a' }};
                --theme-text-onAccent: {{ $themeConfiguration['tokens']['text']['onAccent'] ?? '#0b0f12' }};

                --theme-status-success: {{ $themeConfiguration['tokens']['status']['success'] ?? '#22c55e' }};
                --theme-status-warning: {{ $themeConfiguration['tokens']['status']['warning'] ?? '#f59e0b' }};
                --theme-status-danger: {{ $themeConfiguration['tokens']['status']['danger'] ?? '#ef4444' }};
                --theme-status-info: {{ $themeConfiguration['tokens']['status']['info'] ?? '#38bdf8' }};

                --theme-inputs-background: {{ $themeConfiguration['tokens']['inputs']['background'] ?? $themeConfiguration['colors']['background'] }};
                --theme-inputs-surface: {{ $themeConfiguration['tokens']['inputs']['surface'] ?? $themeConfiguration['colors']['headers'] }};
                --theme-inputs-border: {{ $themeConfiguration['tokens']['inputs']['border'] ?? '#27272a' }};
                --theme-inputs-focus: {{ $themeConfiguration['tokens']['inputs']['focus'] ?? $themeConfiguration['colors']['primary'] }};
                --theme-inputs-text: {{ $themeConfiguration['tokens']['inputs']['text'] ?? '#f8fafc' }};
                --theme-inputs-placeholder: {{ $themeConfiguration['tokens']['inputs']['placeholder'] ?? '#9ca3af' }};

                --theme-interactive-accent: {{ $themeConfiguration['tokens']['interactive']['accent'] ?? $themeConfiguration['colors']['primary'] }};
                --theme-interactive-accentMuted: {{ $themeConfiguration['tokens']['interactive']['accentMuted'] ?? '#14532d' }};
                --theme-interactive-accentHover: {{ $themeConfiguration['tokens']['interactive']['accentHover'] ?? '#22c55e' }};
                --theme-interactive-selection: {{ $themeConfiguration['tokens']['interactive']['selection'] ?? 'rgba(34,197,94,0.25)' }};

                --theme-borders-subtle: {{ $themeConfiguration['tokens']['borders']['subtle'] ?? '#1f2937' }};
                --theme-borders-strong: {{ $themeConfiguration['tokens']['borders']['strong'] ?? '#374151' }};
            }

            body {
                background-color: var(--theme-base-background);
            }
        </style>

        @if(!empty($siteConfiguration['captcha']['enabled']) && !empty($siteConfiguration['captcha']['siteKey']))
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onTurnstileLoad" async defer></script>
        @endif

        @yield('assets')

        @include('layouts.scripts')

        @viteReactRefresh
        @vite('resources/scripts/index.tsx')
    </head>
    <body>
        @section('content')
            @yield('above-container')
            @yield('container')
            @yield('below-container')
        @show
    </body>
</html>
