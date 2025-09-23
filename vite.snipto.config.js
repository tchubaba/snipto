import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    root: process.cwd(),
    build: {
        outDir: 'dist/snipto', // separate from publicDir
        emptyOutDir: true,
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/js/standalone/snipto.js'),
            output: {
                entryFileNames: 'snipto.js', // keep readable
            },
        },
        minify: false, // snipto.js should not be minified
    },
});
