import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    publicDir: false, // don't copy favicons, robots.txt, etc.

    build: {
        outDir: 'public/js',
        emptyOutDir: false, // don't wipe public/js
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/js/standalone/snipto.js'),
            output: {
                entryFileNames: 'snipto.js',
                // If you import things like alpinejs, qrcode, etc.,
                // Rollup will expect them as globals instead of bundling.
                globals: {
                    alpinejs: 'Alpine',
                    axios: 'axios',
                    qrcode: 'QRCode',
                },
            },
            external: [
                'alpinejs',
                'axios',
                'qrcode',
            ],
        },
        minify: false,
        sourcemap: true, // optional, makes it nice for debugging
    },
});
