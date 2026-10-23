import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';

// E2E walkthrough of all four protection modes against a running Snipto instance.
// Local: `make up` first, then `npm run test:e2e`. The base URL follows the
// APP_PORT in the local .env (the same value the dev stack binds to), falling
// back to the .env.example default of 8080. E2E_BASE_URL overrides both.
// CI: the app is booted by .github/workflows/test.yml before the test step.
//
// Why these settings:
// - workers: 1 — every encrypted mode runs Argon2id at m=64 MiB; parallel
//   derivations multiply memory on small runners and make timing flaky.
// - expect 15s / test 120s — crypto-bound, not network-bound. A derivation is
//   0.5-2s in headless Chromium; the defaults (5s) flake on shared runners.
// - chromium only — libsodium WASM + WebCrypto behavior is engine-consistent;
//   Tor/Mullvad (the privacy browsers that motivated libsodium over WebCrypto
//   X25519) are a manual matrix, not CI.
function localAppPort() {
    try {
        const match = readFileSync(new URL('.env', import.meta.url), 'utf8')
            .match(/^APP_PORT=(\d+)/m);
        if (match) return match[1];
    } catch {
        // No local .env — fall through to the .env.example default.
    }
    return '8080';
}

const BASE_URL = process.env.E2E_BASE_URL || `http://localhost:${localAppPort()}`;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 2 : 0,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: BASE_URL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
