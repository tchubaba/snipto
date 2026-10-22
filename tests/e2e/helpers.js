import { test as base, expect } from '@playwright/test';

// Unique slug per run: slugs are random >=8 chars (F-005 floor) so runs never
// collide with leftovers; single-use rows self-consume anyway.
export function uniqueSlug(prefix) {
    const rnd = Math.random().toString(36).slice(2, 8);
    return `${prefix}${Date.now().toString(36)}${rnd}`.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40);
}

// Slug at-or-above the 4-char all-modes floor but below the 8-char plaintext
// floor (F-005 boundary tests). Tail of timestamp+random keeps it unique per run.
export function shortSlug(len = 6) {
    const src = (Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/g, '');
    return src.slice(-len);
}

// Random password: >=12 chars (Password mode floor) that can never trip the
// weak-password modal. The old format (`e2e-<ts36><rand36>`) flaked ~25% of
// runs: when the base36 tail ended in 1-3 digits, analyzePasswordStrength's
// `[a-z]{4,}[0-9]{1,3}$` penalty fired (-2), dropping the score below 3 and
// opening the modal, which hid success-url-input until the 30s waitFor timed
// out. This format scores >=5 deterministically: 3 word-like tokens of >=3
// chars (+2), hyphen symbol (+1), length >=14 (+2), and the tail ALWAYS ends
// in a letter so the trailing-digits penalty can never match. Padding letters
// avoid 'abcd'/'qwerty' substrings; blacklist entries are dictionary words,
// never reachable from a timestamp+random root.
export function uniquePassword() {
    const ts = Date.now().toString(36);
    const rnd = (Math.random().toString(36).slice(2) + 'kmnpqrs').replace(/[^a-z0-9]/g, '');
    const tail = rnd.slice(0, 5) + 'kmnpqrs'[Math.floor(Math.random() * 7)];
    return `e2e-${ts}-${tail}`;
}

export function uniqueSentinel(label) {
    return `${label}-SENTINEL-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

// Test-scoped context factory. ALWAYS use this instead of browser.newContext():
// contexts created straight from the worker-scoped `browser` fixture stay open
// until WORKER teardown, not test teardown — and with workers:1 one worker (one
// browser) runs the whole suite, so every leaked page keeps its renderer, Alpine
// timers and Vite HMR websocket alive for the rest of the run. Traced flakes
// showed CDP round-trips degrading to 20-30s once earlier tests' pages pile up,
// which desyncs Alpine state from the test's click sequence. This fixture
// closes every context it created when the test ends, failure included.
export const test = base.extend({
    newContext: async ({ browser }, use) => {
        const created = [];
        await use(async (opts) => {
            const ctx = await browser.newContext(opts);
            created.push(ctx);
            return ctx;
        });
        for (const ctx of created.reverse()) {
            await ctx.close().catch(() => {});
        }
    },
});

export { expect };

// Drive the creation form on /{slug} for a given protection mode and return the
// share URL shown on the success screen (read from the DOM — no clipboard).
export async function createSnipto(page, sniptoSlug, { mode, content, password, sniptoId }) {
    await page.goto(`/${sniptoSlug}`);
    await page.getByTestId(`mode-btn-${mode}`).click();
    await page.getByTestId('content-textarea').fill(content);
    if (mode === 2) await page.getByTestId('create-password-input').fill(password);
    if (mode === 3) await page.getByTestId('create-sniptoid-input').fill(sniptoId);
    await page.getByTestId('create-submit').click();

    const urlInput = page.getByTestId('success-url-input');
    // Argon2id derivation + POST can take a few seconds; be generous.
    await urlInput.waitFor({ state: 'visible', timeout: 30_000 });
    return urlInput.inputValue();
}

// The payload renders inside a sandboxed srcdoc iframe with a randomized id
// (snipto.js contentHostId), scoped under the stable container.
export async function expectPayloadShows(page, sentinel) {
    const frame = page.locator('#snipto-payload-container iframe').contentFrame();
    await expect(frame.locator('body')).toContainText(sentinel, { timeout: 20_000 });
}

// Toast (showToastMessage) — used for decryption-failure feedback.
export async function expectToast(page, text) {
    await expect(page.getByRole('status').or(page.locator('[x-show="showToast"]'))
        .filter({ hasText: text }).first()).toBeVisible({ timeout: 15_000 });
}

// Open the header locale dropdown idempotently: only click the toggle when the
// panel is actually closed. Blind toggle clicks assume open/close parity, but
// the panel carries @click.away="open = false" and x-transition — under load
// Alpine's `open` can lag behind dispatched clicks, and a parity assumption
// then leaves the next menu-item click retrying "element is not visible"
// forever (observed: 235 retries until the 120s test timeout). Returns the
// open panel locator.
export async function openLocaleMenu(dropdown) {
    const panel = dropdown.locator('div[x-show="open"]');
    if (await panel.isVisible().catch(() => false)) return panel;
    await dropdown.locator('button').first().click();
    await expect(panel).toBeVisible({ timeout: 5_000 });
    return panel;
}
