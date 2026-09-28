import { readFileSync } from 'node:fs';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react-swc';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { paraglideVitePlugin } from '@inlang/paraglide-js';
import { fileURLToPath, URL } from 'node:url';
import { extensionAllowlistPlugin } from './build/extensionAllowlist.ts';

const catalogPrefix = 'virtual:m12-i18n-catalog/';
const resolvedCatalogPrefix = `\0${catalogPrefix}`;
const generatedCatalogDirectory = fileURLToPath(new URL('./src/i18n/generated/', import.meta.url));

/** Keep generated catalog implementations out of TypeScript's source graph. */
function generatedCatalogPlugin(): Plugin {
    return {
        name: 'm12labs-generated-i18n-catalogs',
        resolveId(id) {
            return id.startsWith(catalogPrefix) ? `\0${id}` : null;
        },
        load(id) {
            if (!id.startsWith(resolvedCatalogPrefix)) return null;

            const [scope, locale, ...extra] = id.slice(resolvedCatalogPrefix.length).split('/');
            if (extra.length || !['full', 'app', 'public'].includes(scope ?? '') || !/^[A-Za-z0-9_-]+$/.test(locale ?? '')) {
                throw new Error(`Invalid generated locale catalog ${JSON.stringify(id)}`);
            }
            return readFileSync(`${generatedCatalogDirectory}${scope}/${locale}.js`, 'utf8');
        },
    };
}

// Self-contained v2 UI. Builds into the Laravel public dir at `public/build`
// and writes its dev hot-file to `public/hot`, so it can be served by the
// existing Laravel app at `/v2` without touching the V1 build pipeline.
export default defineConfig(({ command }) => ({
    plugins: [
        // Compile messages/*.json for hot reload in development. Production uses
        // scripts/compile-i18n.mjs before tsc, with a content-hash cache so an
        // extension rebuild does not pay the Paraglide compile cost unnecessarily.
        //
        // Dev only (`command === 'serve'`): here it compiles on boot and watches
        // messages/*.json for live recompiles. In `vite build` we deliberately
        // omit it to avoid compiling twice. Locale-module output keeps generated
        // file count tiny; the compact typed facade is generated separately.
        ...(command === 'serve'
            ? [
                  paraglideVitePlugin({
                      project: './project.inlang',
                      outdir: './src/paraglide',
                      strategy: ['localStorage', 'baseLocale'],
                      localStorageKey: 'm12labs.locale',
                      outputStructure: 'locale-modules',
                  }),
              ]
            : []),
        generatedCatalogPlugin(),
        extensionAllowlistPlugin(
            fileURLToPath(new URL('./src/extensions/installed.json', import.meta.url)),
            fileURLToPath(new URL('./src/', import.meta.url)),
        ),
        react(),
        tailwindcss(),
        laravel({
            input: ['src/main.tsx', 'src/public.tsx'],
            publicDirectory: '../public',
            buildDirectory: 'build',
            hotFile: '../public/hot',
            refresh: false,
        }),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
        // Extension packages are compiled into this bundle. A package that
        // pulled in its own copy of React would produce two runtimes in one
        // tree — hooks throw, context silently misses. These stay single-copy.
        // CodeMirror checks extensions (and lezer highlight tags) by identity:
        // a lockfile refresh once left codemirror@6.0.2 on a newer
        // @codemirror/state than ours and the file editor threw "Unrecognized
        // extension value in extension set". @lezer/common numbers its node
        // props per copy, so with two copies (language on 1.5.2, highlight on
        // 1.5.3) every StreamLanguage mode (properties, ini, shell, toml)
        // looked up the wrong prop and crashed with "e is not iterable".
        dedupe: [
            'react',
            'react-dom',
            'react-router-dom',
            '@tanstack/react-query',
            '@codemirror/state',
            '@codemirror/view',
            '@codemirror/language',
            '@lezer/highlight',
            '@lezer/common',
        ],
    },

    build: {
        emptyOutDir: true,
        target: 'es2022',
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        // The framework, pinned to a chunk of its own. Left to the
                        // automatic split it was merged with shared app modules into
                        // one ~117 KB gzip chunk whose hash changed on every core
                        // deploy and every extension install (each is a rebuild), so
                        // every visitor re-downloaded unchanged React. This only
                        // changes when the dependencies do.
                        {
                            name: 'vendor-react',
                            test: /node_modules[\\/](react|react-dom|scheduler|react-router|react-router-dom|@tanstack[\\/](react-query|query-core))[\\/]/,
                            priority: 20,
                        },
                    ],
                },
            },
        },
    },

    server: {
        port: 5174,
        strictPort: true,
        cors: { origin: '*' },
    },
}));
