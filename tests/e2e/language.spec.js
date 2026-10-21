import { test, expect } from '@playwright/test';
import { uniqueSlug, uniqueSentinel, createSnipto, expectPayloadShows } from './helpers.js';

// The language-switcher guard (snipto.js handleLanguageChangeRequest) fires
// only while showPayload is true — the RECIPIENT's decrypted "Here's your
// snipto:" view — because the locale switch reloads the page and the ephemeral
// payload would be lost from the screen. (The creator's success screen uses
// showSuccess and is NOT guarded — see remediation notes.)
// Pins: locale submit is intercepted on the payload view, cancel keeps the
// decrypted payload intact, "Proceed" performs the reload in the new locale.
test('language switch on payload view warns; cancel keeps it, proceed reloads localized', async ({ browser }) => {
    const slug = uniqueSlug('lg');
    const sentinel = uniqueSentinel('LANG');

    const creator = await browser.newContext();
    const url = await createSnipto(await creator.newPage(), slug, { mode: 0, content: sentinel });

    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(url);
    await expectPayloadShows(page, sentinel);
    await expect(page.getByText('Here’s your snipto:')).toBeVisible();

    const dropdown = page.locator('[x-data="localeDropdown()"]');

    // Request Spanish: intercepted by the warning modal.
    await dropdown.locator('button').first().click();
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
    await dropdown.locator('button').first().click();
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
test('locale dropdown lists all supported locales and each switch localizes the UI', async ({ browser }) => {
    // Display names come from config('app.supported_locales') and are NOT
    // themselves translated — stable selectors. Keep in sync with that config
    // (pinned server-side by SupportedLocalesTest).
    const switches = [
        { name: 'Português', marker: 'Obtenha seu Snipto ID' },
        { name: 'Español (AI)', marker: 'Obtén tu Snipto ID' },
        { name: 'Français (AI)', marker: 'Obtenez votre Snipto ID' },
        { name: 'Deutsch (AI)', marker: 'Holen Sie sich Ihre Snipto-ID' },
    ];

    const page = await (await browser.newContext()).newPage();
    await page.goto('/');

    // All five options render (English included; it is simply not switched to).
    // Scope to the open menu div: once a locale becomes current, the toggle
    // button carries the same accessible name as its menu item.
    const dropdown = page.locator('[x-data="localeDropdown()"]');
    const menu = () => dropdown.locator('div[x-show="open"]');
    await dropdown.locator('button').first().click();
    for (const name of ['English', ...switches.map(s => s.name)]) {
        await expect(menu().getByRole('button', { name })).toBeVisible();
    }
    await dropdown.locator('button').first().click(); // toggle closes the menu

    for (const { name, marker } of switches) {
        await dropdown.locator('button').first().click();
        await menu().getByRole('button', { name }).click();
        await expect(page.getByRole('link', { name: marker })).toBeVisible({ timeout: 15_000 });
    }
});
