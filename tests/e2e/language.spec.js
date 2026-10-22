import { test, expect, openLocaleMenu } from './helpers.js';
import { uniqueSlug, uniqueSentinel, shortSlug, createSnipto, expectPayloadShows } from './helpers.js';

// The language-switcher guard (snipto.js handleLanguageChangeRequest) fires
// only while showPayload is true — the RECIPIENT's decrypted "Here's your
// snipto:" view — because the locale switch reloads the page and the ephemeral
// payload would be lost from the screen. (The creator's success screen uses
// showSuccess and is NOT guarded — see remediation notes.)
// Pins: locale submit is intercepted on the payload view, cancel keeps the
// decrypted payload intact, "Proceed" performs the reload in the new locale.
test('language switch on payload view warns; cancel keeps it, proceed reloads localized', async ({ newContext }) => {
    const slug = uniqueSlug('lg');
    const sentinel = uniqueSentinel('LANG');

    const creator = await newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 0, content: sentinel });

    const context = await newContext();
    const page = await context.newPage();
    await page.goto(url);
    await expectPayloadShows(page, sentinel);
    await expect(page.getByText('Here’s your snipto:')).toBeVisible();

    const dropdown = page.locator('[x-data="localeDropdown()"]');

    // Request Spanish: intercepted by the warning modal.
    await openLocaleMenu(dropdown);
    await page.getByRole('button', { name: 'Español (AI)' }).click();

    const modal = page.getByRole('dialog').filter({ hasText: 'copied your Snipto' });
    await expect(modal).toBeVisible();

    // Cancel: payload view untouched — iframe and sentinel still present.
    await page.getByRole('button', { name: 'Go Back' }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByText('Here’s your snipto:')).toBeVisible();
    const frame = page.locator('#snipto-payload-container iframe').contentFrame();
    await expect(frame.locator('body')).toContainText(sentinel);

    // Proceed: pending form submits, page reloads localized (Spanish header
    // nav). The snipto was already consumed by this recipient, so the reload
    // lands on the creation form — in Spanish.
    await openLocaleMenu(dropdown);
    await page.getByRole('button', { name: 'Español (AI)' }).click();
    await expect(modal).toBeVisible();
    await page.getByRole('button', { name: 'Proceed' }).click();

    await expect(page.getByRole('link', { name: 'Obtén tu Snipto ID' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('create-submit')).toBeVisible();
});

// Pins the switcher itself (not the interception): the dropdown offers exactly
// the five supported locales, and switching to each one actually localizes the
// UI. Switches run sequentially in one context, which also pins cookie
// persistence across changes. No payload is displayed, so no modal intercepts.
test('locale dropdown lists all supported locales and each switch localizes the UI', async ({ page }) => {
    // Display names come from config('app.supported_locales') and are NOT
    // themselves translated — stable selectors. Keep in sync with that config
    // (pinned server-side by SupportedLocalesTest).
    const switches = [
        { name: 'Português', marker: 'Obtenha seu Snipto ID' },
        { name: 'Español (AI)', marker: 'Obtén tu Snipto ID' },
        { name: 'Français (AI)', marker: 'Obtenez votre Snipto ID' },
        { name: 'Deutsch (AI)', marker: 'Holen Sie sich Ihre Snipto-ID' },
    ];

    await page.goto('/');

    // All five options render (English included; it is simply not switched to).
    // Scope to the open menu div: once a locale becomes current, the toggle
    // button carries the same accessible name as its menu item.
    const dropdown = page.locator('[x-data="localeDropdown()"]');
    const menu = () => dropdown.locator('div[x-show="open"]');
    const toggle = dropdown.locator('button').first();
    await openLocaleMenu(dropdown);
    for (const name of ['English', ...switches.map(s => s.name)]) {
        await expect(menu().getByRole('button', { name })).toBeVisible();
    }

    for (const { name, marker } of switches) {
        await openLocaleMenu(dropdown);
        const item = menu().getByRole('button', { name });
        try {
            // Bounded click: if the menu closed under us mid-action (Alpine
            // state lag under load), fail fast into the resync path instead of
            // letting Playwright retry "not visible" until the test timeout.
            await item.click({ timeout: 8_000 });
        } catch {
            if (await menu().isVisible().catch(() => false)) await toggle.click();
            await openLocaleMenu(dropdown);
            await item.click();
        }
        await expect(page.getByRole('link', { name: marker })).toBeVisible({ timeout: 15_000 });
    }
});

// Helper: switch the UI to Português via the dropdown (cookie-backed locale).
async function switchToPortuguese(page) {
    await page.goto('/');
    const dropdown = page.locator('[x-data="localeDropdown()"]');
    const menu = await openLocaleMenu(dropdown);
    await menu.getByRole('button', { name: 'Português' }).click();
    await expect(page.getByRole('link', { name: 'Obtenha seu Snipto ID' })).toBeVisible({ timeout: 15_000 });
}

// The non-English wordlists contain accented words (pt_BR "fósseis") that are
// NOT in the slug route charset. generateDicewareSlug must sample only the
// pure a-z subset, so a Portuguese suggestion is always ASCII and navigable.
test('portuguese: "Create one for me" suggests an ASCII-only slug', async ({ page }) => {
    await switchToPortuguese(page);

    await page.goto(`/${shortSlug(3)}`);
    await expect(page.getByTestId('suggest-slug')).toBeVisible();
    await page.getByTestId('suggest-slug').click();

    // pt_BR: 3 words, pure a-z + hyphens only.
    await expect(page).toHaveURL(/\/[a-z]+(?:-[a-z]+){2}$/);
    const slug = new URL(page.url()).pathname.slice(1);
    expect(slug.length).toBeGreaterThanOrEqual(8);
    await expect(page.getByTestId('create-submit')).toBeVisible();
});

// SetLocale runs as global middleware, so route-less responses (a slug that
// fails the route regex never matches a route, so web-group middleware never
// run) are localized too — and the selector toggle reflects the effective
// locale instead of a session value nothing writes.
test('404 page is localized and the selector shows the current locale', async ({ page }) => {
    await switchToPortuguese(page);

    await page.goto('/inválido!'); // accented + '!' -> no route match -> 404
    await expect(page.getByText('Opa! Isso não é um snipto válido!')).toBeVisible();
    await expect(page.getByText('Voltar à home page')).toBeVisible();

    const dropdown = page.locator('[x-data="localeDropdown()"]');
    await expect(dropdown.locator('button').first()).toContainText('Português');
});
