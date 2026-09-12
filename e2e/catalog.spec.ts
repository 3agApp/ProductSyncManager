import { expect, test } from '@playwright/test';
import { chooseFromSelect, SHOP, watchForErrors } from './helpers';

test('products can be filtered by category and brand', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/products`);

    const total = await page.getByTestId('product-row').count();

    await chooseFromSelect(page, 'Filter by brand', 'No brand');
    await expect(page).toHaveURL(/brand=none/);
    await expect(page.getByTestId('product-row')).not.toHaveCount(total);

    await page.getByTestId('clear-filters').click();
    await expect(page.getByTestId('product-row')).toHaveCount(total);

    await chooseFromSelect(page, 'Filter by category', 'Bags > Totes');
    await expect(page).toHaveURL(/category=\d+/);

    await errors.assertNone();
});

test('the product table sorts and changes page size', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/products`);

    await page.getByTestId('sort-price').click();
    await expect(page).toHaveURL(/sort=price/);

    // Clicking the same column again turns the order around.
    await page.getByTestId('sort-price').click();
    await expect(page).toHaveURL(/direction=asc/);

    await chooseFromSelect(page, 'Products per page', '25 / page');
    await expect(page).toHaveURL(/per_page=25/);

    await errors.assertNone();
});

test('several products can be changed at once', async ({ page }, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/products`);
    await page.getByTestId('select-all').click();

    await expect(page.getByTestId('bulk-bar')).toBeVisible();
    await expect(page.getByTestId('bulk-count')).toContainText('selected');

    await page.getByTestId('bulk-status').click();
    await page.getByRole('menuitem', { name: 'Draft' }).click();

    await expect(page.getByTestId('bulk-bar')).toBeHidden();
    await expect(
        page.getByRole('status').or(page.getByText(/products updated/i)),
    ).toBeVisible();

    await errors.assertNone();
});

test('the category tree folds and filters', async ({ page }, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/categories`);

    const all = await page.getByTestId('category-row').count();

    await page.getByTestId('toggle-all-categories').click();
    const folded = await page.getByTestId('category-row').count();
    expect(folded).toBeLessThan(all);

    await page.getByTestId('toggle-all-categories').click();
    await expect(page.getByTestId('category-row')).toHaveCount(all);

    // A search reveals matches wherever they sit in the tree.
    await page.getByTestId('category-filter').fill('totes');
    await expect(page.getByTestId('category-count')).toContainText('of');
    await expect(page.getByText('Totes', { exact: true })).toBeVisible();

    await errors.assertNone();
});

test('a category can be added and removed', async ({ page }, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/categories`);

    await page.getByTestId('category-name').fill('Playwright Goods');
    await page.getByTestId('category-submit').click();

    // The name also lands in the parent select, so look in the table.
    const row = page
        .getByRole('table')
        .getByText('Playwright Goods', { exact: true });
    await expect(row).toBeVisible();

    await page.getByRole('button', { name: 'Delete Playwright Goods' }).click();
    await page.getByTestId('delete-term-confirm').click();

    await expect(row).toBeHidden();
    await errors.assertNone();
});

test('the product form keeps its categories and warns about unsaved work', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);

    await page.goto(`/${SHOP}/products`);
    await page
        .getByTestId('product-row')
        .first()
        .getByTestId('product-actions')
        .click();
    await page.getByTestId('product-edit').click();

    await expect(
        page.getByRole('heading', { name: 'Edit product' }),
    ).toBeVisible();

    // Adding a category shows it as a chip and marks the form unsaved.
    await page.getByTestId('product-categories').fill('Playwright > Nested');
    await page.getByTestId('category-add').click();

    await expect(
        page.getByTestId('category-chips').getByText('Playwright > Nested'),
    ).toBeVisible();
    await expect(page.getByTestId('unsaved-notice')).toBeVisible();

    page.once('dialog', (dialog) => dialog.dismiss());
    await page.getByRole('link', { name: 'Categories' }).click();

    // Declining the warning keeps you on the form.
    await expect(
        page.getByRole('heading', { name: 'Edit product' }),
    ).toBeVisible();

    await errors.assertNone();
});

test('sorting while search is pending keeps both choices', async ({ page }) => {
    await page.goto(`/${SHOP}/products`);
    await page.clock.install();
    await page.getByTestId('product-search').fill('bag');
    await page.getByTestId('sort-price').click();
    await expect(page).toHaveURL(/search=bag/);
    await expect(page).toHaveURL(/sort=price/);
    await page.clock.runFor(500);
    await expect(page).toHaveURL(/search=bag/);
    await expect(page).toHaveURL(/sort=price/);
    await expect(page.getByTestId('product-search')).toHaveValue('bag');
});
