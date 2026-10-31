import { test, expect } from './helpers.js';
import { uniqueSlug, uniqueSentinel, createSnipto, expectPayloadShows } from './helpers.js';

// Mode 1 (URL Secret): key travels only in the URL fragment (#k=). The fragment
// never reaches the server; retrieval is POST /reveal with reveal_token +
// key_hash in the BODY. This spec pins the protocol at the network layer too:
// no request URL may ever carry key_hash or reveal_token (F-002 regression).
test('url-secret create -> fragment-only key -> retrieve', async ({ newContext }) => {
    const slug = uniqueSlug('sec');
    const sentinel = uniqueSentinel('SECRET');

    const creator = await newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 1, content: sentinel });
    expect(url).toContain('#k=');

    const recipient = await newContext();
    const page = await recipient.newPage();

    const apiRequests = [];
    page.on('request', (req) => {
        if (req.url().includes('/api/snipto/')) apiRequests.push(req);
    });

    await page.goto(url); // fragment is sent to the page, not the server
    await expectPayloadShows(page, sentinel);

    const reveal = apiRequests.filter((r) => r.url().includes('/reveal'));
    expect(reveal.length).toBe(1);
    expect(reveal[0].method()).toBe('POST');

    // Probe GET must precede the reveal POST — and there must be exactly ONE
    // probe. Mode 1 used to re-GET inside retrieveAndDecrypt for the nonce and
    // a fresh token; the page-load probe already returns both, and each extra
    // GET overwrites the token and spends a show-snipto throttle slot.
    const probes = apiRequests.filter((r) => r.method() === 'GET' && r.url().endsWith(`/api/snipto/${slug}`));
    expect(probes.length).toBe(1);
    expect(apiRequests.indexOf(probes[0])).toBeLessThan(apiRequests.indexOf(reveal[0]));

    // Zero-knowledge pin: secrets never appear in any request URL.
    for (const r of apiRequests) {
        expect(r.url()).not.toMatch(/key_hash/i);
        expect(r.url()).not.toMatch(/reveal_token/i);
        expect(r.url()).not.toContain('#k=');
    }
});
