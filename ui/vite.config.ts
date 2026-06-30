import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react-swc';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';

// Self-contained v2 UI. Builds into the Laravel public dir at `public/build-v2`
// and writes its dev hot-file to `public/hot-v2`, so it can be served by the
// existing Laravel app at `/v2` without touching the V1 build pipeline.
export default defineConfig({
    plugins: [
        react(),
        tailwindcss(),
        laravel({
            input: ['src/main.tsx'],
            publicDirectory: '../public',
            buildDirectory: 'build-v2',
            hotFile: '../public/hot-v2',
            refresh: false,
        }),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },

    build: {
        emptyOutDir: true,
        target: 'es2022',
    },

    server: {
        port: 5174,
        strictPort: true,
        cors: { origin: '*' },
    },
});
