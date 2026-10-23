import { test, expect } from './helpers.js';
import { uniqueSlug, uniqueSentinel, createSnipto, expectPayloadShows, expectToast } from './helpers.js';

// Mode 3 (Snipto ID) full loop, including ID GENERATION on /sniptoid:
//   1. recipient generates an ID (diceware passphrase -> reveal gate -> derive)
//   2. sender encrypts to that ID on /{slug}
//   3. recipient re-derives their keypair from passphrase in a fresh context
// TRAP: sniptoid.js clears the passphrase field after successful derivation,
// so the passphrase MUST be read from the input before clicking generate.
test('snipto ID: generate -> encrypt -> decrypt with passphrase', async ({ newContext }) => {
    // --- 1. Generate a Snipto ID -------------------------------------------
    const idCtx = await newContext();
    const idPage = await idCtx.newPage();
    await idPage.goto('/sniptoid');

    await idPage.getByTestId('sniptoid-diceware-btn').click(); // auto-reveals
    const passphraseInput = idPage.getByTestId('sniptoid-passphrase-input');
    // The diceware loader lazy-fetches the locale wordlist on first call, so
    // the input fills asynchronously — wait for it instead of racing the fetch.
    await expect.poll(async () => (await passphraseInput.inputValue()).length, { timeout: 10_000 })
        .toBeGreaterThanOrEqual(20);
    const passphrase = await passphraseInput.inputValue();
    // Pin the generator's contract, not just its length: hyphen-joined
    // lowercase words from the locale wordlist (en: 6 words, >=5 joining
    // hyphens; EFF "yo-yo"-style entries add extras, so no exact count).
    expect(passphrase).toMatch(/^[a-z]+(?:-[a-z]+){5,}$/);

    await idPage.getByTestId('sniptoid-generate-btn').click();
    const idInput = idPage.getByTestId('sniptoid-id-input');
    await idInput.waitFor({ state: 'visible', timeout: 30_000 }); // Argon2id + X25519
    const sniptoId = await idInput.inputValue();
    expect(sniptoId).toMatch(/^[A-Za-z0-9+/]{64}$/);

    // --- 2. Sender encrypts to the ID --------------------------------------
    const slug = uniqueSlug('id');
    const sentinel = uniqueSentinel('SNIPTOID');
    const sender = await newContext();
    await createSnipto(await sender.newPage(), slug, { mode: 3, content: sentinel, sniptoId });
    // (createSnipto returns the URL but the fragment is empty for mode 3; use slug URL)
    const url = `${process.env.E2E_BASE_URL || 'http://localhost:8008'}/${slug}`;

    // --- 3. Recipient decrypts in a fresh context --------------------------
    const recipient = await newContext();
    const page = await recipient.newPage();
    await page.goto(url);

    const prompt = page.getByTestId('unlock-passphrase-input');
    await expect(prompt).toBeVisible();

    // Below the 20-char passphrase floor: client-side toast, no server call,
    // snipto untouched (Passphrase::MIN_LENGTH mirror in snipto.js).
    await prompt.fill('too-short');
    await page.getByTestId('unlock-passphrase-btn').click();
    await expectToast(page, 'at least 20 characters');
    await expect(page.locator('#snipto-payload-container iframe')).toHaveCount(0);

    // Wrong passphrase: server 403s on key_hash before consuming; toast + retry.
    await prompt.fill(passphrase + 'x');
    await page.getByTestId('unlock-passphrase-btn').click();
    await expectToast(page, 'try again');
    await expect(page.locator('#snipto-payload-container iframe')).toHaveCount(0);

    await prompt.fill(passphrase);
    await page.getByTestId('unlock-passphrase-btn').click();
    await expectPayloadShows(page, sentinel);
});

// Mode-3 creation gate (snipto.js submitSnipto): empty and malformed Snipto
// IDs are rejected client-side with toasts — no crypto work, no POST, no
// success screen. The 64-char format check mirrors the ID the /sniptoid page
// produces (base64 of salt(16) || pubkey(32)).
test('snipto ID editor: empty and malformed IDs are rejected client-side', async ({ page }) => {
    const slug = uniqueSlug('id');
    await page.goto(`/${slug}`);
    await page.getByTestId('mode-btn-3').click();
    await page.getByTestId('content-textarea').fill(uniqueSentinel('ID-GATE'));

    await page.getByTestId('create-submit').click();
    await expectToast(page, 'enter a Snipto ID');

    // 63 chars + '!' -> fails both length and charset.
    await page.getByTestId('create-sniptoid-input').fill('a'.repeat(63) + '!');
    await page.getByTestId('create-submit').click();
    await expectToast(page, 'Invalid Snipto ID format');

    await expect(page.getByTestId('success-url-input')).toBeHidden();
});

// Audit finding F-2 regression (reject path): a malicious/compromised server
// can hand the recipient a small-order sender_public_key (u-coordinate 1 is
// a point of order 4) to force a predictable shared secret — a Lim-style
// key-recovery attempt against the long-lived Snipto ID key, amplified by the
// key_hash we would POST as an offline oracle. The client must reject the key
// BEFORE deriving or POSTing key_hash and show the generic decryption-failure
// screen — identical to any other decrypt failure, so the attacker gains no
// oracle from our error handling either.
test('snipto ID: server-supplied small-order sender key is rejected before reveal', async ({ newContext }) => {
    const slug = uniqueSlug('trap');

    // Plant the trap snipto directly through the API — a real sender's
    // browser would never produce this sender_public_key (0x01 || 0x00*31).
    const plantCtx = await newContext();
    const plant = await plantCtx.newPage();
    await plant.goto('/');
    const created = await plant.evaluate(async (body) => {
        const res = await fetch('/api/snipto', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(body),
        });
        return { status: res.status, body: await res.json() };
    }, {
        slug,
        protection_type: 3,
        payload: 'aGVsbG8=',
        key_hash: 'a'.repeat(64),
        nonce: '0123456789abcdef01234567',
        sender_public_key: btoa('\x01' + '\x00'.repeat(31)),
        recipient_salt: btoa('\x00'.repeat(16)),
        key_provider_type: 'passphrase',
    });
    expect(created.status).toBe(200);
    expect(created.body.success).toBe(true);

    // Recipient opens the link: the probe hands them the trap key. Unlocking
    // must land on the generic integrity screen, and no reveal POST may ever
    // fire (that POST carries the key_hash oracle the attack is after).
    const ctx = await newContext();
    const page = await ctx.newPage();
    const revealPosts = [];
    page.on('request', (req) => {
        if (req.method() === 'POST' && req.url().includes('/reveal')) revealPosts.push(req.url());
    });

    await page.goto(`/${slug}`);
    await page.getByTestId('unlock-passphrase-input').waitFor({ timeout: 10000 });
    await page.getByTestId('unlock-passphrase-input').fill('e2e trap-test passphrase 2026');
    await page.getByTestId('unlock-passphrase-btn').click();

    await expect(page.getByText('Decryption failed: the key or the encrypted data could not be verified.'))
        .toBeVisible({ timeout: 20000 });
    expect(revealPosts).toEqual([]);
});
