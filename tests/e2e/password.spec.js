import { test, expect } from '@playwright/test';
import { uniqueSlug, uniqueSentinel, uniquePassword, createSnipto, expectPayloadShows, expectToast } from './helpers.js';

// Mode 2 (Password): wrong password -> server 403 on key_hash BEFORE
// decrementViews (ApiController::reveal), so the snipto is NOT burned and the
// client re-probes for a fresh reveal token (ensureRevealToken) and retries.
// This spec pins that whole retry path end-to-end.
test('password create -> wrong password retryable -> retrieve', async ({ browser }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PASSWORD');
    const password = uniquePassword();

    const creator = await browser.newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 2, content: sentinel, password });

    const recipient = await browser.newContext();
    const page = await recipient.newPage();
    await page.goto(url);

    const prompt = page.getByTestId('unlock-password-input');
    await expect(prompt).toBeVisible();

    // Wrong password: toast, still on the prompt, snipto still alive.
    await prompt.fill(password + 'x');
    await page.getByTestId('unlock-password-btn').click();
    await expectToast(page, 'try again');
    await expect(page.locator('#snipto-payload-container iframe')).toHaveCount(0);

    // Correct password: fresh token is fetched automatically.
    await prompt.fill(password);
    await page.getByTestId('unlock-password-btn').click();
    await expectPayloadShows(page, sentinel);
});

// The metadata GET (reveal-token issuance) must NOT consume the snipto: a
// recipient that opens the page and leaves before unlocking leaves it fully
// intact for the next visitor.
test('password: abandoned retrieval does not burn the snipto', async ({ browser }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-KEEP');
    const password = uniquePassword();

    const creator = await browser.newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 2, content: sentinel, password });

    const abandoner = await browser.newContext();
    const p1 = await abandoner.newPage();
    await p1.goto(url);
    await expect(p1.getByTestId('unlock-password-input')).toBeVisible(); // GET probe done, token issued
    await abandoner.close();

    const recipient = await browser.newContext();
    const p2 = await recipient.newPage();
    await p2.goto(url);
    await p2.getByTestId('unlock-password-input').fill(password);
    await p2.getByTestId('unlock-password-btn').click();
    await expectPayloadShows(p2, sentinel);
});

// The weak-password warning is client-side only (analyzePasswordStrength < 3
// gates submitSnipto behind a modal; "Use anyway" re-submits with bypass=true).
// Pins: modal fires, cancel leaves the snipto uncreated, bypass still produces
// a fully functional (if weakly protected) snipto.
test('password: weak password triggers warning modal; cancel blocks, bypass proceeds', async ({ browser }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-WEAK');
    // 14 chars, two common words, no digits/symbols -> strength score 2 (< 3),
    // verified not in public/blacklist.txt.
    const weakPassword = 'oppositevelvet';

    const creator = await browser.newContext();
    const page = await creator.newPage();
    await page.goto(`/${slug}`);
    await page.getByTestId('mode-btn-2').click();
    await page.getByTestId('content-textarea').fill(sentinel);
    await page.getByTestId('create-password-input').fill(weakPassword);
    await page.getByTestId('create-submit').click();

    const modal = page.getByRole('dialog').filter({ hasText: 'Weak Password' });
    await expect(modal).toBeVisible();

    // Cancel: modal closes, no success screen, and a reload still shows the
    // creation form — nothing was created server-side.
    await page.getByRole('button', { name: 'Go back and change' }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByTestId('success-url-input')).toBeHidden();
    await page.reload();
    await expect(page.getByTestId('create-submit')).toBeVisible();

    // "Use anyway": bypasses the gate and creates the snipto.
    await page.getByTestId('mode-btn-2').click();
    await page.getByTestId('content-textarea').fill(sentinel);
    await page.getByTestId('create-password-input').fill(weakPassword);
    await page.getByTestId('create-submit').click();
    await expect(modal).toBeVisible();
    await page.getByRole('button', { name: 'Use anyway' }).click();
    const urlInput = page.getByTestId('success-url-input');
    await urlInput.waitFor({ state: 'visible', timeout: 30_000 });

    // The bypassed weak password still decrypts normally.
    const recipient = await browser.newContext();
    const p2 = await recipient.newPage();
    await p2.goto(await urlInput.inputValue());
    await p2.getByTestId('unlock-password-input').fill(weakPassword);
    await p2.getByTestId('unlock-password-btn').click();
    await expectPayloadShows(p2, sentinel);
});
