import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { chooseFromSelect, SHOP } from './helpers';

async function addProduct(page: Page, name: string, categories: string[]) {
    await page.goto(`/${SHOP}/products/create`);
    await page.getByTestId('product-name').fill(name);
    await page.getByTestId('product-sku').fill(name.replaceAll(' ', '-'));
    await page.getByTestId('product-price').fill('12.50');
    await page.getByTestId('product-stock').fill('10');
    for (const category of categories) {
        await page.getByTestId('product-categories').fill(category);
        await page.getByTestId('category-add').click();
    }
    await page.getByTestId('product-submit').click();
    await expect(page).toHaveURL(new RegExp(`/${SHOP}/products$`));
}

test('a category branch can be removed while moving its products', async ({
    page,
}) => {
    await addProduct(page, 'Move exclusive', ['Move root > Move child']);
    await addProduct(page, 'Move shared', [
        'Move root > Move child',
        'Move destination',
    ]);
    await page.goto(`/${SHOP}/categories`);
    await page
        .getByRole('button', { name: 'Delete Move root', exact: true })
        .click();
    await page
        .getByRole('radio', { name: /Delete all child categories/ })
        .check();
    await page
        .getByRole('radio', { name: /Move products to another category/ })
        .check();
    await expect(page.getByTestId('category-delete-preview')).toContainText(
        '2 unique products affected',
    );
    await chooseFromSelect(page, 'Destination category', 'Move destination');
    await page.getByTestId('delete-term-confirm').click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Delete Move root', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Delete Move child', exact: true }),
    ).toHaveCount(0);
    const row = page
        .getByTestId('category-row')
        .filter({ hasText: 'Move destination' });
    await expect(row.getByTestId('product-count-link')).toHaveText('2');
    await row.getByTestId('product-count-link').click();
    await expect(
        page.getByTestId('product-row').filter({ hasText: 'Move exclusive' }),
    ).toBeVisible();
    await expect(
        page.getByTestId('product-row').filter({ hasText: 'Move shared' }),
    ).toBeVisible();
});

test('permanent deletion requires confirmation and preserves shared products', async ({
    page,
}) => {
    await addProduct(page, 'Delete exclusive', ['Delete root > Delete child']);
    await addProduct(page, 'Delete shared', [
        'Delete root > Delete child',
        'Delete survivor',
    ]);
    await page.goto(`/${SHOP}/categories`);
    await page
        .getByRole('button', { name: 'Delete Delete root', exact: true })
        .click();
    await page
        .getByRole('radio', { name: /Delete all child categories/ })
        .check();
    await page
        .getByRole('radio', { name: /Delete products exclusive/ })
        .check();
    await expect(page.getByTestId('category-delete-preview')).toContainText(
        '1 product will be permanently deleted. 1 shared product will be kept.',
    );
    await expect(page.getByTestId('delete-term-confirm')).toBeDisabled();
    await page.getByLabel('Type “Delete root” to confirm').fill('Delete root');
    await page.getByTestId('delete-term-confirm').click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await page.goto(`/${SHOP}/products?search=Delete`);
    await expect(
        page.getByTestId('product-row').filter({ hasText: 'Delete exclusive' }),
    ).toHaveCount(0);
    await expect(
        page.getByTestId('product-row').filter({ hasText: 'Delete shared' }),
    ).toBeVisible();
});

test('a failed preview blocks deletion and can be retried', async ({
    page,
}) => {
    await page.goto(`/${SHOP}/categories`);
    await page.route('**/deletion-preview*', (route) =>
        route.fulfill({ status: 503, json: { message: 'Unavailable' } }),
    );
    await page.getByTestId('category-delete').first().click();
    await expect(page.getByRole('alert')).toContainText('Could not load');
    await expect(page.getByTestId('delete-term-confirm')).toBeDisabled();
    await page.unroute('**/deletion-preview*');
    await page.getByRole('button', { name: 'Refresh preview' }).click();
    await expect(page.getByTestId('delete-term-confirm')).toBeEnabled();
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
});
