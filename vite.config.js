import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'localhost',
        },
        cors: true,
        headers: {
            'Cross-Origin-Resource-Policy': 'cross-origin',
            'Cross-Origin-Embedder-Policy': 'require-corp',
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
        viteStaticCopy({
            targets: [
                {
                    src: 'resources/js/snipto.js',
                    dest: 'js',
                },
                {
                    src: 'resources/js/sniptoid.js',
                    dest: 'js',
                },
            ],
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                entryFileNames: 'js/[name]-[hash].js',
            },
        },
        minify: 'esbuild',
    },
});
