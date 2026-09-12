import { expect, test } from '@playwright/test';
import { SHOP, watchForErrors } from './helpers';

/**
 * Every page a signed in user can reach should render.
 *
 * This is the cheapest guard against the failure that leaves nothing on the
 * screen at all, which no amount of server side testing can see.
 */
const PAGES = [
    { path: `/${SHOP}/dashboard`, heading: 'Dashboard' },
    { path: `/${SHOP}/products`, heading: 'Products' },
    { path: `/${SHOP}/products/create`, heading: 'Add product' },
    { path: `/${SHOP}/categories`, heading: 'Categories' },
    { path: `/${SHOP}/brands`, heading: 'Brands' },
    { path: '/settings/profile', heading: 'Profile' },
    { path: '/settings/shops', heading: 'Shops' },
    { path: '/settings/appearance', heading: 'Appearance settings' },
    { path: `/settings/shops/${SHOP}`, heading: 'Shop settings' },
    { path: `/settings/shops/${SHOP}/sync`, heading: 'WooCommerce connection' },
];

for (const { path, heading } of PAGES) {
    test(`${path} renders`, async ({ page }, testInfo) => {
        const errors = watchForErrors(page, testInfo);

        await page.goto(path);

        await expect(
            page.getByRole('heading', { name: heading, exact: true }).first(),
        ).toBeVisible();

        await errors.assertNone();
    });
}

test('the product edit page renders', async ({ page }, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/products`);
    await page
        .getByTestId('product-row')
        .first()
        .getByTestId('product-detail-open')
        .click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');
    await page
        .getByTestId('product-row')
        .first()
        .getByTestId('product-actions')
        .click();
    await page.getByTestId('product-edit').click();

    await expect(
        page.getByRole('heading', { name: 'Edit product' }),
    ).toBeVisible();

    await errors.assertNone();
});
