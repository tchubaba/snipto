import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/standalone/snipto.js', // add standalone file
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                // Keep the standalone file name readable
                entryFileNames: (chunk) => {
                    if (chunk.name === 'snipto') return 'js/snipto.js';
                    return 'js/[name]-[hash].js';
                },
            },
        },
        minify: (file) => file.name !== 'snipto', // disable minify only for snipto.js
    },
});
