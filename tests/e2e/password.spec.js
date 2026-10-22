import { test, expect } from './helpers.js';
import { uniqueSlug, uniqueSentinel, uniquePassword, createSnipto, expectPayloadShows, expectToast } from './helpers.js';

// Mode 2 (Password): wrong password -> server 403 on key_hash BEFORE
// decrementViews (ApiController::reveal), so the snipto is NOT burned and the
// client re-probes for a fresh reveal token (ensureRevealToken) and retries.
// This spec pins that whole retry path end-to-end.
test('password create -> wrong password retryable -> retrieve', async ({ newContext }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PASSWORD');
    const password = uniquePassword();

    const creator = await newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 2, content: sentinel, password });

    const recipient = await newContext();
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
test('password: abandoned retrieval does not burn the snipto', async ({ newContext }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-KEEP');
    const password = uniquePassword();

    const creator = await newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 2, content: sentinel, password });

    const abandoner = await newContext();
    const p1 = await abandoner.newPage();
    await p1.goto(url);
    await expect(p1.getByTestId('unlock-password-input')).toBeVisible(); // GET probe done, token issued
    await abandoner.close();

    const recipient = await newContext();
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
test('password: weak password triggers warning modal; cancel blocks, bypass proceeds', async ({ newContext }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-WEAK');
    // 14 chars, two common words, no digits/symbols -> strength score 2 (< 3),
    // verified not in public/blacklist.txt.
    const weakPassword = 'oppositevelvet';

    const creator = await newContext();
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
    const recipient = await newContext();
    const p2 = await recipient.newPage();
    await p2.goto(await urlInput.inputValue());
    await p2.getByTestId('unlock-password-input').fill(weakPassword);
    await p2.getByTestId('unlock-password-btn').click();
    await expectPayloadShows(p2, sentinel);
});

// "Generate for me" in the password editor: diceware-loader fills the field
// with hyphen-joined words from the locale wordlist (6 words on the English
// EFF short list), auto-reveals it, and the result must clear the
// weak-password gate (analyzePasswordStrength >= 3) so creation proceeds
// without the modal — then the generated passphrase actually decrypts.
test('password: "Generate for me" fills a strong passphrase that skips the modal and decrypts', async ({ newContext }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-GEN');

    const creator = await newContext();
    const page = await creator.newPage();
    await page.goto(`/${slug}`);
    await page.getByTestId('mode-btn-2').click();
    await page.getByTestId('content-textarea').fill(sentinel);

    // The loader lazy-fetches the wordlist on first click — poll the input.
    await page.getByTestId('generate-password-btn').click();
    const input = page.getByTestId('create-password-input');
    await expect.poll(async () => (await input.inputValue()).length, { timeout: 10_000 })
        .toBeGreaterThanOrEqual(12);
    const password = await input.inputValue();

    // Hyphen-joined lowercase words; 6 joining hyphens minimum for the en
    // 6-word default (EFF entries like "yo-yo" add extra hyphens, so no
    // exact word count is pinned).
    expect(password).toMatch(/^[a-z]+(?:-[a-z]+){5,}$/);

    // Auto-reveal: generatePassword() sets passwordRevealed, flipping the
    // input to type=text so the user sees what they must save.
    await expect(input).toHaveAttribute('type', 'text');

    // Submit must NOT raise the weak-password modal.
    await page.getByTestId('create-submit').click();
    await expect(page.getByRole('dialog').filter({ hasText: 'Weak Password' })).toBeHidden();
    const urlInput = page.getByTestId('success-url-input');
    await urlInput.waitFor({ state: 'visible', timeout: 30_000 });

    // The generated passphrase decrypts in a fresh context.
    const recipient = await newContext();
    const p2 = await recipient.newPage();
    await p2.goto(await urlInput.inputValue());
    await p2.getByTestId('unlock-password-input').fill(password);
    await p2.getByTestId('unlock-password-btn').click();
    await expectPayloadShows(p2, sentinel);
});

// The expire-after selector (1h/1d/1w buttons in the password section) must
// reach the server in the contract ApiController::store validates
// ('in:1h,1d,1w'; password mode allows up to 1 week). A malformed or
// unaccepted value would 422 the POST and show the error path instead of the
// success screen. Scoped to the password section because the Snipto-ID
// section renders an identical button group (hidden under mode 2).
test('password: "1 Week" expiration selection is accepted end-to-end', async ({ newContext }) => {
    const slug = uniqueSlug('pw');
    const sentinel = uniqueSentinel('PW-1W');
    const password = uniquePassword();

    const creator = await newContext();
    const page = await creator.newPage();
    await page.goto(`/${slug}`);
    await page.getByTestId('mode-btn-2').click();
    await page.getByTestId('content-textarea').fill(sentinel);
    await page.getByTestId('create-password-input').fill(password);
    await page.locator('[x-show="protectionType === 2"]').getByRole('button', { name: '1 Week' }).click();
    await page.getByTestId('create-submit').click();

    await expect(page.getByTestId('success-url-input')).toBeVisible({ timeout: 30_000 });
});
