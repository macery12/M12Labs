/// <reference types="vitest" />
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { dirname, resolve } from 'pathe';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const plugins = [
    react({
        babel: {
            plugins: ['babel-plugin-macros', 'babel-plugin-styled-components'],
        },
    }),
];

if (process.env.VITEST === undefined) {
    plugins.push(
        laravel({
            input: 'resources/scripts/index.tsx',
        }),
    );
}

export default defineConfig({
    define:
        process.env.VITEST === undefined
            ? {
                  'process.env': {},
                  'process.platform': null,
                  'process.version': null,
                  'process.versions': null,
              }
            : undefined,

    plugins,

    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    // CodeMirror editor and Lezer parser stack
                    if (id.includes('@codemirror') || id.includes('@lezer')) return 'vendor-editor';
                    // xterm terminal emulator and addons
                    if (id.includes('xterm')) return 'vendor-terminal';
                    // Chart.js and react-chartjs-2
                    if (id.includes('chart.js') || id.includes('react-chartjs-2')) return 'vendor-charts';
                    // Stripe payment libraries
                    if (id.includes('@stripe')) return 'vendor-stripe';
                    // FontAwesome icon packages
                    if (id.includes('@fortawesome')) return 'vendor-fa';
                    // State management: easy-peasy wraps immer + redux
                    if (
                        id.includes('easy-peasy') ||
                        id.includes('/immer/') ||
                        id.includes('/redux/') ||
                        id.includes('/redux-thunk/')
                    )
                        return 'vendor-state';
                    // Framer Motion animation runtime
                    if (id.includes('framer-motion')) return 'vendor-motion';
                    // CSS-in-JS runtime (styled-components)
                    if (id.includes('styled-components')) return 'vendor-styled';
                    // Preact/compat (react alias) and React Router
                    if (id.includes('preact') || id.includes('react-router') || id.includes('@remix-run'))
                        return 'vendor-react';
                },
            },
        },
    },

    server: {
        cors: {
            origin: '*',
        },
    },

    resolve: {
        alias: {
            '@': resolve(dirname(fileURLToPath(import.meta.url)), 'resources', 'scripts'),
            '@definitions': resolve(
                dirname(fileURLToPath(import.meta.url)),
                'resources',
                'scripts',
                'api',
                'definitions',
            ),
            '@feature': resolve(
                dirname(fileURLToPath(import.meta.url)),
                'resources',
                'scripts',
                'components',
                'server',
                'features',
            ),
            '@account': resolve(
                dirname(fileURLToPath(import.meta.url)),
                'resources',
                'scripts',
                'components',
                'account',
            ),
            '@server': resolve(dirname(fileURLToPath(import.meta.url)), 'resources', 'scripts', 'components', 'server'),
            '@admin': resolve(dirname(fileURLToPath(import.meta.url)), 'resources', 'scripts', 'components', 'admin'),

            react: 'preact/compat',
            'react-dom': 'preact/compat',
            'react/jsx-runtime': 'preact/jsx-runtime',
            'react-dom/test-utils': 'preact/test-utils',
        },
    },

    test: {
        environment: 'happy-dom',
        include: ['resources/scripts/**/*.{spec,test}.{ts,tsx}'],
    },
});
