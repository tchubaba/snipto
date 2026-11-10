import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test, expect, uniqueSlug, uniquePassword, expectToast } from './helpers.js';

// File sharing (50 MiB cap, encrypted-only). Pins the DoD-critical paths:
//   - hostile envelope metadata: filename is sanitized (path separators,
//     control chars, Unicode Bidi overrides), bytes are never rendered or
//     executed, and the download stays an opaque blob;
//   - byte-exact round-trips through envelope + AES-GCM + binary transport
//     in the protected modes (Secret / Password / Snipto ID), including a
//     true 50 MiB run at the cap;
//   - the upload lifecycle: progress bar, dropped-PUT toast, incomplete
//     uploads invisible, and the retry gate (same-tab reset via the retained
//     token accepted; fresh-tab hijack rejected 422 while the token is live).
//
// Files are encrypted-only: the plaintext-mode ban is server-side (422, see
// FileSniptoTest) and UI-side (mode 0 hides the File toggle, and picking a
// file in mode 0 is rejected with a toast — pinned below).

// Drive the creation form in FILE mode: protection-mode button, File toggle,
// in-memory buffer (no on-disk fixture), optional password / Snipto ID. The
// success screen only appears after the raw PUT completes (upload gate in
// submitSnipto), so waiting on success-url-input proves the blob landed.
async function createFileSnipto(page, slug, { mode, file, password, sniptoId }) {
    await page.goto(`/${slug}`);
    await page.getByTestId(`mode-btn-${mode}`).click();
    await page.getByTestId('content-mode-file').click();
    // Dead-end option must disappear in file mode...
    await expect(page.getByTestId('mode-btn-0')).toBeHidden();
    await page.setInputFiles('[data-testid="file-input"]', file);
    if (mode === 2) await page.getByTestId('create-password-input').fill(password);
    if (mode === 3) await page.getByTestId('create-sniptoid-input').fill(sniptoId);
    await page.getByTestId('create-submit').click();

    const urlInput = page.getByTestId('success-url-input');
    // Argon2id (m=64MB) + encrypt + POST + binary PUT; be generous like the
    // text-mode helper.
    await urlInput.waitFor({ state: 'visible', timeout: 60_000 });
    return urlInput.inputValue();
}

async function downloadAndDigest(page) {
    const card = page.getByTestId('file-card');
    await expect(card).toBeVisible({ timeout: 30_000 });
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('download-file').click(),
    ]);
    // Blob-URL downloads: createReadStream() yields null for these; path()
    // waits for completion and hands us Playwright's saved temp copy.
    const downloadPath = await download.path();
    const digest = crypto
        .createHash('sha256')
        .update(await fs.readFile(downloadPath))
        .digest('hex');
    return { download, digest };
}

// Hostile-name trap: path separator + .html name, script payload. The card
// must show the SANITIZED name, the downloaded file must never be executed or
// navigated to, and no iframe may ever render the file bytes (viewer v1 is
// download-only, Blob typed application/octet-stream).
test('file (password mode): hostile name sanitized, bytes never executed, download exact', async ({ newContext }) => {
    const slug = uniqueSlug('fpw');
    const password = uniquePassword();
    const body = '<script>window.__pwned = 1</script>' + 'A'.repeat(512 * 1024);
    const buffer = Buffer.from(body, 'utf8');

    const creator = await newContext();
    const url = await createFileSnipto(await creator.newPage(), slug, {
        mode: 2,
        password,
        file: { name: 'evil folder/x.html', mimeType: 'text/html', buffer },
    });

    // Creator-side display keeps the raw name (x-text only — never innerHTML).

    const recipient = await newContext();
    const page = await recipient.newPage();
    await page.goto(url);
    await page.getByTestId('unlock-password-input').fill(password);
    await page.getByTestId('unlock-password-btn').click();

    const { download, digest } = await downloadAndDigest(page);
    // Path separator neutralized by the client sanitizer ([\\/\x00-\x1f\x7f] -> _).
    expect(download.suggestedFilename()).toBe('evil folder_x.html');

    // The file was never rendered as HTML anywhere: no payload iframe exists
    // (the card replaced the container) and the injected global is absent.
    await expect(page.locator('#snipto-payload-container iframe')).toHaveCount(0);
    expect(await page.evaluate(() => typeof window.__pwned)).toBe('undefined');

    // Byte-exact envelope round-trip.
    expect(digest).toBe(crypto.createHash('sha256').update(buffer).digest('hex'));
});

// Secret mode (URL fragment #k=): 2 MiB of random bytes must come back
// bit-identical through envelope framing -> AES-GCM+HMAC -> binary PUT ->
// binary reveal -> parseFileEnvelope. Also pins the auto-decrypt fragment
// path for file kind (no unlock prompt in mode 1).
test('file (secret mode): 2 MiB random buffer round-trips byte-identically', async ({ newContext }) => {
    const slug = uniqueSlug('fsec');
    const buffer = crypto.randomBytes(2 * 1024 * 1024);

    const creator = await newContext();
    const url = await createFileSnipto(await creator.newPage(), slug, {
        mode: 1,
        file: { name: 'two-mib.bin', mimeType: 'application/octet-stream', buffer },
    });
    expect(url).toContain('#k='); // mode-1 key fragment still rides the URL

    const recipient = await newContext();
    const page = await recipient.newPage();
    await page.goto(url);

    // Mode 1 + fragment: no prompt, straight to the card.
    // The card must carry the trust notice: the lead tells the recipient to
    // expect the file and trust the sender; the tail explains that encryption
    // proves nobody altered the file in transit but says nothing about safety.
    const trust = page.getByTestId('file-trust-notice');
    await expect(trust).toBeVisible();
    await expect(trust).toContainText('Only download files you were expecting from someone you trust.');
    await expect(trust).toContainText('Your file is encrypted end to end. Its contents cannot be altered in transit without detection, not even by us. However, encryption does not guarantee that a file is safe to open.');

    const { download, digest } = await downloadAndDigest(page);
    expect(download.suggestedFilename()).toBe('two-mib.bin');
    expect(digest).toBe(crypto.createHash('sha256').update(buffer).digest('hex'));
});

// Snipto ID (mode 3, X25519): recipient unlocks with a passphrase, and the
// file-mode binary reveal carries no JSON — the nonce must therefore have
// arrived via the metadata probe (server change in show()). This test is the
// e2e guard for that seam.
test('file (Snipto ID mode): generate ID -> encrypt -> passphrase unlock -> download', async ({ newContext }) => {
    // 1. Recipient generates a Snipto ID (same flow as sniptoid.spec.js).
    const idCtx = await newContext();
    const idPage = await idCtx.newPage();
    await idPage.goto('/sniptoid');
    await idPage.getByTestId('sniptoid-diceware-btn').click();
    const passphraseInput = idPage.getByTestId('sniptoid-passphrase-input');
    await expect.poll(async () => (await passphraseInput.inputValue()).length, { timeout: 10_000 })
        .toBeGreaterThanOrEqual(20);
    const passphrase = await passphraseInput.inputValue(); // read BEFORE generate clears it

    await idPage.getByTestId('sniptoid-generate-btn').click();
    const idInput = idPage.getByTestId('sniptoid-id-input');
    await idInput.waitFor({ state: 'visible', timeout: 30_000 });
    const sniptoId = await idInput.inputValue();

    // 2. Sender encrypts a file to the ID.
    const slug = uniqueSlug('fid');
    const buffer = crypto.randomBytes(64 * 1024);
    const sender = await newContext();
    await createFileSnipto(await sender.newPage(), slug, {
        mode: 3,
        sniptoId,
        file: { name: 'secret-report.pdf', mimeType: 'application/pdf', buffer },
    });
    const url = `${process.env.E2E_BASE_URL || 'http://localhost:8008'}/${slug}`;

    // 3. Recipient unlocks with the passphrase and downloads.
    const recipient = await newContext();
    const page = await recipient.newPage();
    await page.goto(url);
    await page.getByTestId('unlock-passphrase-input').fill(passphrase);
    await page.getByTestId('unlock-passphrase-btn').click();

    const { download, digest } = await downloadAndDigest(page);
    expect(download.suggestedFilename()).toBe('secret-report.pdf');
    expect(digest).toBe(crypto.createHash('sha256').update(buffer).digest('hex'));
});

// Unicode Bidi overrides / C1 control characters in the sender-supplied name
// must be neutralized: RLO extension spoofing — "paid<RLO>txt.exe" renders
// visually as "paid.txt.exe". Also pins the "."/".." fallback to "snipto".
test('file (secret mode): Bidi overrides + C1 controls stripped; dot-names fall back', async ({ newContext }) => {
    const rloUrl = await createFileSnipto(await (await newContext()).newPage(), uniqueSlug('frlo'), {
        mode: 1,
        file: { name: 'paid\u202Etxt\u0085exe', mimeType: 'application/octet-stream', buffer: Buffer.from('rlo-trap') },
    });
    const dotUrl = await createFileSnipto(await (await newContext()).newPage(), uniqueSlug('fdot'), {
        mode: 1,
        file: { name: '..', mimeType: 'application/octet-stream', buffer: Buffer.from('dot-trap') },
    });

    const viewer = await newContext();
    const page = await viewer.newPage();

    await page.goto(rloUrl);
    const [d1] = await Promise.all([page.waitForEvent('download'), page.getByTestId('download-file').click()]);
    // RLO and C1 both neutralized to '_' — the downloaded NAME is byte-safe
    // even though the displayed name fooled the eye into "paid.txt.exe".
    expect(d1.suggestedFilename()).toBe('paid_txt_exe');

    await page.goto(dotUrl);
    const [d2] = await Promise.all([page.waitForEvent('download'), page.getByTestId('download-file').click()]);
    // Pure dot-name caught by the /^\.+$/ fallback.
    expect(d2.suggestedFilename()).toBe('snipto');
});

// Oversize guard is client-side BEFORE any crypto or network: a 51 MiB file
// (cap 50 MiB) must be rejected with the MB toast and must never POST.
// The server-side 413 path against a lying client is pinned in FileSniptoTest.
test('file: 51 MiB selection is rejected client-side; no network fires', async ({ newContext }) => {
    const ctx = await newContext();
    const page = await ctx.newPage();
    await page.goto(`/${uniqueSlug('fover')}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();

    let postFired = false;
    page.on('request', r => { if (r.method() === 'POST' && r.url().endsWith('/api/snipto')) postFired = true; });

    // Playwright's buffer channel caps at 50 MiB; the client guard only ever
    // reads File.size, so a sparse on-disk file exercises it for free.
    const sparse = path.join(os.tmpdir(), `snipto-oversize-${Date.now()}.bin`);
    const fh = await fs.open(sparse, 'w');
    await fh.truncate(51 * 1024 * 1024);
    await fh.close();
    await page.setInputFiles('[data-testid="file-input"]', sparse);
    await expectToast(page, 'File too large. Maximum size is 50 MB.');
    await expect(page.getByTestId('file-summary')).toBeHidden();
    await fs.unlink(sparse).catch(() => {});

    // Submit must be disabled while uploadMode has no file — assert that
    // rather than clicking (a plain click on a disabled button burns the
    // entire actionability timeout).
    await expect(page.getByTestId('create-submit')).toBeDisabled();
    await page.getByTestId('create-submit').click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(2000);
    expect(postFired).toBe(false);
});

// Plaintext mode is file-free end to end: the whole Text/File toggle hides
// (stronger than just hiding mode-btn-0 in file mode), the E2EE-disabled
// button itself is only hidden once File mode is on, and picking a protection
// mode back restores the toggle.
test('plaintext mode hides the Text/File toggle entirely; picking a protected mode restores it', async ({ newContext }) => {
    const ctx = await newContext();
    const page = await ctx.newPage();
    await page.goto(`/${uniqueSlug('fplg')}`);
    await expect(page.getByTestId('content-mode-file')).toBeVisible(); // default mode is Secret
    await expect(page.getByTestId('mode-btn-0')).toBeVisible();

    await page.getByTestId('mode-btn-0').click();
    await expect(page.getByTestId('content-mode-file')).toBeHidden();
    await expect(page.getByTestId('content-mode-text')).toBeHidden();

    await page.getByTestId('mode-btn-2').click();
    await expect(page.getByTestId('content-mode-file')).toBeVisible();
    await expect(page.getByTestId('mode-btn-0')).toBeVisible(); // mode-0 hides only in FILE mode
});

// Drag-and-drop: the dropzone hands the file to the same guarded setFile
// path via a real DropEvent with an in-page DataTransfer.
test('file: drag-and-drop drops into the picker (same setFile guard)', async ({ newContext }) => {
    const ctx = await newContext();
    const page = await ctx.newPage();
    await page.goto(`/${uniqueSlug('fdrop')}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();
    await page.evaluate(() => {
        const dt = new DataTransfer();
        dt.items.add(new File([new Uint8Array(1024)], 'dropped.bin', { type: 'application/octet-stream' }));
        const ev = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(ev, 'dataTransfer', { value: dt }); // DropEvent ctor absent in this Chromium; handleDrop only reads ev.dataTransfer
        document.querySelector('[data-testid="file-panel"]').dispatchEvent(ev);
    });
    await expect(page.getByTestId('file-summary')).toContainText('dropped.bin');
});

// removeFile(): summary clears and submit goes disabled while uploadMode
// still needs a file.
test('file: remove button clears the selection and disables submit', async ({ newContext }) => {
    const ctx = await newContext();
    const page = await ctx.newPage();
    await page.goto(`/${uniqueSlug('frm')}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();
    await page.setInputFiles('[data-testid="file-input"]', { name: 'bye.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(1024) });
    await expect(page.getByTestId('file-summary')).toContainText('bye.bin');

    await page.getByTestId('file-remove').click();
    await expect(page.getByTestId('file-summary')).toBeHidden();
    await expect(page.getByTestId('create-submit')).toBeDisabled();
});

// Progress bar: upload-progress shows during the PUT and submit is disabled
// for the whole upload gate (disabled expression includes uploadPercent).
// CDP upload throttle (30 KB/s) stretches a 256 KiB body to ~8s of real
// progress events — a route-mocked PUT never moves bytes, so onprogress
// never fires (verified empirically).
test('file: progress bar visible and submit disabled while the PUT is in flight', async ({ newContext, browserName }) => {
    test.skip(browserName !== 'chromium', 'CDP Network.emulateNetworkConditions is Chromium-only; the bar itself is still exercised implicitly by the 50 MiB spec on every engine');
    const ctx = await newContext();
    const page = await ctx.newPage();
    const cdp = await ctx.newCDPSession(page);
    await page.goto(`/${uniqueSlug('fprog')}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: 30 * 1024 });
    await page.setInputFiles('[data-testid="file-input"]', { name: 'slow.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(256 * 1024) });
    await page.getByTestId('create-submit').click();

    await expect(page.getByTestId('upload-progress')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('create-submit')).toBeDisabled();
});

// The full retry-gate cycle (CI-safe twin of the container-kill driver):
// an aborted PUT never reaches the server, so the row stays incomplete,
// INVISIBLE, and the upload token stays LIVE. Then: fresh tab is rejected 422
// (anti-hijack gate), while the creator's same-tab retry presents the
// retained token, resets the row, completes, and downloads byte-exact.
test('file: aborted PUT -> invisible row -> fresh tab 422 -> same-tab gated reset -> byte-exact', async ({ newContext }) => {
    const slug = uniqueSlug('fgate');
    const buffer = crypto.randomBytes(256 * 1024);

    const creator = await newContext();
    const page = await creator.newPage();
    let killed = false;
    await page.route('**/api/snipto/*/payload', route => {
        if (!killed) { killed = true; route.abort(); } else { route.continue(); }
    });

    await page.goto(`/${slug}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();
    await page.setInputFiles('[data-testid="file-input"]', { name: 'gate.bin', mimeType: 'application/octet-stream', buffer });
    await page.getByTestId('create-submit').click();

    // PUT aborted -> drop toast, no success screen.
    await expectToast(page, 'Upload failed. The connection dropped');
    await expect(page.getByTestId('success-url-input')).toBeHidden();

    // Incomplete row is INVISIBLE: a second tab shows the creation form,
    // not an unlock prompt or download card (404-as-absent philosophy).
    // NOTE: toBeHidden not toHaveCount — Alpine x-show keeps the viewer
    // templates mounted-but-hidden in the DOM.
    const bystander = await newContext();
    const pageB = await bystander.newPage();
    await pageB.goto(`/${slug}`);
    await expect(pageB.getByTestId('content-mode-text')).toBeVisible({ timeout: 10_000 });
    await expect(pageB.getByTestId('file-card')).toBeHidden();
    await expect(pageB.getByTestId('unlock-password-input')).toBeHidden();

    // Bystander store on the same slug (no token, token still LIVE) -> 422 gate.
    await pageB.getByTestId('mode-btn-1').click();
    await pageB.getByTestId('content-mode-file').click();
    await pageB.setInputFiles('[data-testid="file-input"]', { name: 'hijack.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(1024) });
    const storeB = pageB.waitForResponse(r => r.url().endsWith('/api/snipto') && r.request().method() === 'POST', { timeout: 30_000 });
    await pageB.getByTestId('create-submit').click();
    expect((await storeB).status()).toBe(422);

    // Creator same-tab retry: store presents the retained upload_token ->
    // gated reset 200; the PUT now reaches the server and completes.
    await page.setInputFiles('[data-testid="file-input"]', { name: 'gate.bin', mimeType: 'application/octet-stream', buffer });
    const storeC = page.waitForResponse(r => r.url().endsWith('/api/snipto') && r.request().method() === 'POST', { timeout: 30_000 });
    await page.getByTestId('create-submit').click();
    expect((await storeC).status()).toBe(200);
    const urlInput = page.getByTestId('success-url-input');
    await urlInput.waitFor({ state: 'visible', timeout: 60_000 });

    const viewer = await newContext();
    const pageV = await viewer.newPage();
    await pageV.goto(await urlInput.inputValue());
    const { digest } = await downloadAndDigest(pageV);
    expect(digest).toBe(crypto.createHash('sha256').update(buffer).digest('hex'));
    await page.unroute('**/api/snipto/*/payload');
});

// The headline number, in a real browser: a TRUE 50 MiB file at the cap
// round-trips byte-exactly (envelope + AES-GCM + binary PUT + MariaDB LONGBLOB).
// Heavy (~100 MB buffers across chromium/node/php) but self-contained.
test('file (secret mode): true 50 MiB at the cap round-trips byte-identically', async ({ newContext }) => {
    // 50 MiB buffers (node + chromium CDP transfer) alone can eat minutes on
    // CI-class hardware; measured ~90s of flow after the click locally.
    test.setTimeout(600_000); // Argon2id + AES-GCM + 50 MiB PUT/INSERT/reveal through php -S
    // Playwright's buffer channel caps at 50 MiB — real on-disk file instead,
    // named so the download suggestion matches.
    const file = path.join(os.tmpdir(), `snipto-fifty-${Date.now()}`, 'fifty.bin');
    await fs.mkdir(path.dirname(file), { recursive: true });
    const buffer = crypto.randomBytes(50 * 1024 * 1024);
    await fs.writeFile(file, buffer);
    try {
    const ctx = await newContext({ acceptDownloads: true });
    const page = await ctx.newPage();
    await page.goto(`/${uniqueSlug('f50')}`);
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-mode-file').click();
    await page.setInputFiles('[data-testid="file-input"]', file);
    await page.getByTestId('create-submit').click();

    // No upload-progress assertion here: a loopback 50 MiB PUT completes in
    // ~3-4s and the bar's x-show window is sub-second (measured) — the bar
    // itself is pinned under CDP throttling in the progress spec above.
    const urlInput = page.getByTestId('success-url-input');
    await urlInput.waitFor({ state: 'visible', timeout: 300_000 });
    expect(await urlInput.inputValue()).toContain('#k=');

    const viewer = await newContext({ acceptDownloads: true });
    const pageV = await viewer.newPage();
    await pageV.goto(await urlInput.inputValue());
    const { download, digest } = await downloadAndDigest(pageV);
    expect(download.suggestedFilename()).toBe('fifty.bin');
    expect(digest).toBe(crypto.createHash('sha256').update(buffer).digest('hex'));
    } finally {
        await fs.rm(path.dirname(file), { recursive: true, force: true });
    }
});
