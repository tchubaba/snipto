import { test, expect } from '@playwright/test';
import { uniqueSlug, uniqueSentinel, createSnipto, expectPayloadShows } from './helpers.js';

// Mode 0 (Plaintext): no crypto, no fragment. Also pins single-use end-to-end:
// a consumed snipto 404s on probe, and init()'s 404 branch falls back to the
// creation form (snipto.js init()).
test('plaintext create -> retrieve -> consumed', async ({ browser }) => {
    const slug = uniqueSlug('pt');
    const sentinel = uniqueSentinel('PLAINTEXT');

    const creator = await browser.newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 0, content: sentinel });
    expect(url).toBe(`${process.env.E2E_BASE_URL || 'http://localhost:8008'}/${slug}`);
    expect(url).not.toContain('#k=');

    // Recipient (fresh context = fresh session, like a real third party).
    const recipient = await browser.newContext();
    const page2 = await recipient.newPage();
    await page2.goto(url);
    await expectPayloadShows(page2, sentinel);

    // Third context: the snipto is consumed -> probe 404s -> creation form shows.
    const third = await browser.newContext();
    const page3 = await third.newPage();
    await page3.goto(url);
    await expect(page3.getByTestId('create-submit')).toBeVisible();
    await expect(page3.locator('#snipto-payload-container iframe')).toHaveCount(0);
});
