import { test, expect } from '@playwright/test';
import { shortSlug } from './helpers.js';

// Welcome page gate: the slug form must mirror the server contract —
// charset [A-Za-z0-9_-]+ (the slug route regex in routes/web.php) and the
// min:4 creation floor in ApiController::store() (F-005). A slug that passes
// here always reaches a loadable editor.
test('welcome form rejects short and invalid slugs, accepts hyphenated ones', async ({ page }) => {
    await page.goto('/');

    // Below the 4-char floor: toast explains the floor, no navigation.
    await page.fill('#slugInput', 'ab');
    await page.click('#sniptoForm button[type="submit"]');
    await expect(page.locator('#toastText')).toContainText('at least 4 characters');
    await expect(page).toHaveURL(/\/$/);

    // Out-of-charset: toast explains the allowed charset, no navigation.
    await page.fill('#slugInput', 'bad.slug!');
    await page.click('#sniptoForm button[type="submit"]');
    await expect(page.locator('#toastText')).toContainText('hyphens');
    await expect(page).toHaveURL(/\/$/);

    // Hyphens/underscores are server-legal and >= 4 chars: navigates to the editor.
    const slug = `ok-${shortSlug(4)}`; // 7 chars, unique per run
    await page.fill('#slugInput', slug);
    await page.click('#sniptoForm button[type="submit"]');
    await expect(page).toHaveURL(new RegExp(`/${slug}$`));
    await expect(page.getByTestId('create-submit')).toBeVisible();
});
