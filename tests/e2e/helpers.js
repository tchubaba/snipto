import { expect } from '@playwright/test';

// Unique slug per run: slugs are random >=8 chars (F-005 floor) so runs never
// collide with leftovers; single-use rows self-consume anyway.
export function uniqueSlug(prefix) {
    const rnd = Math.random().toString(36).slice(2, 8);
    return `${prefix}${Date.now().toString(36)}${rnd}`.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40);
}

// Random password: >=12 chars (Password mode floor), lowercase+digits so it can
// never hit a blacklist entry or trip the weak-password modal.
export function uniquePassword() {
    return `e2e-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

export function uniqueSentinel(label) {
    return `${label}-SENTINEL-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

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
