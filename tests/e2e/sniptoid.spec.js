import { test, expect } from '@playwright/test';
import { uniqueSlug, uniqueSentinel, createSnipto, expectPayloadShows, expectToast } from './helpers.js';

// Mode 3 (Snipto ID) full loop, including ID GENERATION on /sniptoid:
//   1. recipient generates an ID (diceware passphrase -> reveal gate -> derive)
//   2. sender encrypts to that ID on /{slug}
//   3. recipient re-derives their keypair from passphrase in a fresh context
// TRAP: sniptoid.js clears the passphrase field after successful derivation,
// so the passphrase MUST be read from the input before clicking generate.
test('snipto ID: generate -> encrypt -> decrypt with passphrase', async ({ browser }) => {
    // --- 1. Generate a Snipto ID -------------------------------------------
    const idCtx = await browser.newContext();
    const idPage = await idCtx.newPage();
    await idPage.goto('/sniptoid');

    await idPage.getByTestId('sniptoid-diceware-btn').click(); // auto-reveals
    const passphraseInput = idPage.getByTestId('sniptoid-passphrase-input');
    // The diceware loader lazy-fetches the locale wordlist on first call, so
    // the input fills asynchronously — wait for it instead of racing the fetch.
    await expect.poll(async () => (await passphraseInput.inputValue()).length, { timeout: 10_000 })
        .toBeGreaterThanOrEqual(20);
    const passphrase = await passphraseInput.inputValue();

    await idPage.getByTestId('sniptoid-generate-btn').click();
    const idInput = idPage.getByTestId('sniptoid-id-input');
    await idInput.waitFor({ state: 'visible', timeout: 30_000 }); // Argon2id + X25519
    const sniptoId = await idInput.inputValue();
    expect(sniptoId).toMatch(/^[A-Za-z0-9+/]{64}$/);

    // --- 2. Sender encrypts to the ID --------------------------------------
    const slug = uniqueSlug('id');
    const sentinel = uniqueSentinel('SNIPTOID');
    const sender = await browser.newContext();
    await createSnipto(await sender.newPage(), slug, { mode: 3, content: sentinel, sniptoId });
    // (createSnipto returns the URL but the fragment is empty for mode 3; use slug URL)
    const url = `${process.env.E2E_BASE_URL || 'http://localhost:8008'}/${slug}`;

    // --- 3. Recipient decrypts in a fresh context --------------------------
    const recipient = await browser.newContext();
    const page = await recipient.newPage();
    await page.goto(url);

    const prompt = page.getByTestId('unlock-passphrase-input');
    await expect(prompt).toBeVisible();

    // Wrong passphrase: server 403s on key_hash before consuming; toast + retry.
    await prompt.fill(passphrase + 'x');
    await page.getByTestId('unlock-passphrase-btn').click();
    await expectToast(page, 'try again');
    await expect(page.locator('#snipto-payload-container iframe')).toHaveCount(0);

    await prompt.fill(passphrase);
    await page.getByTestId('unlock-passphrase-btn').click();
    await expectPayloadShows(page, sentinel);
});
