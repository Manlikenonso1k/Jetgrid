import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Brand tokens + Filament overrides, injected into the panel head.
                'resources/css/jetgrid.css',
                // The 3D dashboard, mounted into a custom Filament page.
                'resources/js/grid/main.jsx',
                // Standalone art-direction sandbox at /grid-preview (local only).
                'resources/js/grid-preview/main.jsx',
            ],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    server: {
        // Vite's default host is `localhost`, which resolves to ::1 first on
        // Windows — so it binds IPv6 only and public/hot gets http://[::1]:5173.
        // Every asset request from a page opened on 127.0.0.1 is then a
        // cross-origin fetch to a host the browser will not reach, and the whole
        // React layer silently fails to load while Filament (self-hosted assets)
        // keeps working. Pinning to IPv4 keeps the hot URL addressable.
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        // three.js is large; splitting it keeps the rest of the panel snappy.
        rollupOptions: {
            output: {
                manualChunks: {
                    three: ['three'],
                },
            },
        },
    },
});
