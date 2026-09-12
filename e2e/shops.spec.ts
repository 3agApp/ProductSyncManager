import { expect, test } from '@playwright/test';
import { chooseFromSelect, SHOP, watchForErrors } from './helpers';

/**
 * The shop type select, from each place it can be reached.
 *
 * A select that throws when opened takes the whole page with it, so each of
 * these opens it, picks from it, and checks the page is still standing.
 */
test('the shop type select works in the new shop dialog', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto('/settings/shops');
    await page.getByRole('button', { name: 'New shop' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    await chooseFromSelect(page, 'Shop type', 'WordPress');

    await expect(
        dialog.getByRole('combobox', { name: 'Shop type' }),
    ).toHaveText('WordPress');
    await errors.assertNone();
});

test('the shop type select works in the dialog opened from the sidebar', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/dashboard`);
    await page
        .getByRole('button', { name: "Test User's Shop" })
        .first()
        .click();
    await page.getByText('New shop').click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    await chooseFromSelect(page, 'Shop type', 'WordPress');

    await expect(
        dialog.getByRole('combobox', { name: 'Shop type' }),
    ).toHaveText('WordPress');
    await errors.assertNone();
});

test('the shop type select works on the shop settings page', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/settings/shops/${SHOP}`);

    await chooseFromSelect(page, 'Shop type', 'WordPress');

    await expect(page.getByRole('combobox', { name: 'Shop type' })).toHaveText(
        'WordPress',
    );
    await errors.assertNone();
});

test('a shop can be created', async ({ page }, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto('/settings/shops');
    await page.getByRole('button', { name: 'New shop' }).click();

    await page.getByLabel('Shop name').fill('Playwright Emporium');
    await chooseFromSelect(page, 'Shop type', 'WordPress');
    await page.getByRole('button', { name: 'Create shop' }).click();

    // Creating a shop switches to it and opens its settings.
    await expect(
        page.getByRole('heading', { name: 'Edit Playwright Emporium' }),
    ).toBeVisible();
    await expect(page.getByTestId('shop-switcher-trigger')).toHaveText(
        'Playwright Emporium',
    );

    await errors.assertNone();
});
