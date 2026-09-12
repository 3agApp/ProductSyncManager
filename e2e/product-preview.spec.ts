import { expect, test } from '@playwright/test';
import { SHOP } from './helpers';

for (const source of ['products', 'dashboard'] as const) {
    test(`${source} preview contains large galleries and long text`, async ({
        page,
    }) => {
        await page.goto(
            `/${SHOP}/${source === 'products' ? 'dashboard' : 'products'}`,
        );
        await page.route(`**/${SHOP}/${source}`, async (route) => {
            const response = await route.fetch();
            const body = await response.json();
            const product =
                source === 'products'
                    ? body.props.products.data[0]
                    : body.props.recentProducts[0];
            Object.assign(product, {
                name: `Product 2314 gallery regression ${'LongName'.repeat(25)}`,
                sku: 'SKU'.repeat(20),
                brand: 'LongBrand'.repeat(25),
                categories: [`Parent > ${'LongCategory'.repeat(25)}`],
                description: `Description with a long reference: https://example.test/${'reference'.repeat(100)}`,
                imageUrl: 'https://preview-images.test/0.svg',
                galleryImageUrls: Array.from(
                    { length: 9 },
                    (_, i) => `https://preview-images.test/${i + 1}.svg`,
                ),
            });
            await route.fulfill({ response, json: body });
        });
        await page.route('https://preview-images.test/**', (route) =>
            route.fulfill({
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#e5e7eb"/></svg>',
            }),
        );
        await page
            .getByRole('link', {
                name: source === 'products' ? 'Products' : 'Dashboard',
                exact: true,
            })
            .first()
            .click();
        if (source === 'products') {
            await page
                .getByTestId('product-row')
                .first()
                .getByTestId('product-detail-open')
                .click();
        } else {
            await page
                .getByTestId('recent-product')
                .first()
                .getByRole('button')
                .click();
        }
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(page.getByTestId('product-detail-image')).toHaveCount(10);
        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({ width, height: 800 });
            await expect
                .poll(() =>
                    dialog.evaluate(
                        (el) => el.scrollWidth <= el.clientWidth + 1,
                    ),
                )
                .toBe(true);
            const bounds = await dialog.boundingBox();
            expect(bounds!.x).toBeGreaterThanOrEqual(0);
            expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
            expect(bounds!.height).toBeLessThanOrEqual(800 * 0.85 + 1);
        }
        const gallery = page.getByTestId('product-detail-gallery');
        await gallery.scrollIntoViewIfNeeded();
        await gallery.focus();
        await gallery.press('ArrowRight');
        await expect
            .poll(() => gallery.evaluate((el) => el.scrollLeft))
            .toBeGreaterThan(0);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });
}
