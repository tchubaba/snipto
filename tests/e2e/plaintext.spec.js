import { test, expect } from './helpers.js';
import { uniqueSlug, uniqueSentinel, shortSlug, createSnipto, expectPayloadShows } from './helpers.js';

// Mode 0 (Plaintext): no crypto, no fragment. Also pins single-use end-to-end:
// a consumed snipto 404s on probe, and init()'s 404 branch falls back to the
// creation form (snipto.js init()).
test('plaintext create -> retrieve -> consumed', async ({ newContext }) => {
    const slug = uniqueSlug('pt');
    const sentinel = uniqueSentinel('PLAINTEXT');

    const creator = await newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 0, content: sentinel });
    expect(url).toBe(`${process.env.E2E_BASE_URL || 'http://localhost:8008'}/${slug}`);
    expect(url).not.toContain('#k=');

    // Recipient (fresh context = fresh session, like a real third party).
    const recipient = await newContext();
    const page2 = await recipient.newPage();
    await page2.goto(url);
    await expectPayloadShows(page2, sentinel);

    // Third context: the snipto is consumed -> probe 404s -> creation form shows.
    const third = await newContext();
    const page3 = await third.newPage();
    await page3.goto(url);
    await expect(page3.getByTestId('create-submit')).toBeVisible();
    await expect(page3.locator('#snipto-payload-container iframe')).toHaveCount(0);
});

// Slug hardening (F-005) boundary. The server rejects plaintext creation with a
// slug < 8 chars (min:4 for encrypted modes); the UI must surface that instead
// of letting the POST 422 into a generic error:
// - slug < 4: below every floor -> the editor never loads, error message instead.
// - slug 4-7: editor loads, but "E2EE disabled" is disabled with an explanatory
//   tooltip; encrypted modes still work with the same slug.
test('slug under 4 chars: editor never loads', async ({ page }) => {
    await page.goto(`/${shortSlug(3)}`);
    await expect(page.getByText('This link is too short')).toBeVisible();
    await expect(page.getByTestId('create-submit')).toBeHidden();
    await expect(page.getByTestId('mode-btn-1')).toBeHidden();
});

// "Create one for me": the too-short error offers a memorable diceware slug
// (hyphen-joined words; 3 in every locale). Clicking it must reload with a
// slug that clears every floor.
test('slug under 4 chars: "Create one for me" reloads with a diceware slug', async ({ page }) => {
    await page.goto(`/${shortSlug(3)}`);
    await expect(page.getByTestId('suggest-slug')).toBeVisible();
    await page.getByTestId('suggest-slug').click();

    // 3 words in every locale; >= 8 chars total (min possible is 11).
    await expect(page).toHaveURL(/\/[a-z]+(?:-[a-z]+){2}$/);
    const slug = new URL(page.url()).pathname.slice(1);
    expect(slug.length).toBeGreaterThanOrEqual(8);

    // Editor loads and E2EE-disabled is enabled again (slug >= 8).
    await expect(page.getByTestId('create-submit')).toBeVisible();
    await expect(page.getByTestId('mode-btn-0')).toBeEnabled();
});

test('slug 4-7 chars: E2EE-disabled is disabled with a tooltip; encrypted modes still work', async ({ page }) => {
    const slug = shortSlug(6);
    const sentinel = uniqueSentinel('SHORTSLUG');

    await page.goto(`/${slug}`);
    await expect(page.getByTestId('create-submit')).toBeVisible();

    const mode0 = page.getByTestId('mode-btn-0');
    await expect(mode0).toBeVisible();
    await expect(mode0).toBeDisabled();
    await expect(mode0).toHaveAttribute('title', /at least 8 characters/);

    // Same slug must still work in an encrypted mode (floor 4).
    await page.getByTestId('mode-btn-1').click();
    await page.getByTestId('content-textarea').fill(sentinel);
    await page.getByTestId('create-submit').click();
    await expect(page.getByTestId('success-url-input')).toBeVisible({ timeout: 30_000 });
});
