import { expect, test } from '@playwright/test';
import { SHOP, watchForErrors } from './helpers';

test('webhook setup saves securely and fits desktop and mobile', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);
    await page.goto(`/settings/shops/${SHOP}/sync`);
    await page.getByLabel('Store address').fill('https://webhooks.example.com');
    await page.getByLabel('Consumer key', { exact: true }).fill('ck_test');
    await page.getByLabel('Consumer secret', { exact: true }).fill('cs_test');
    await page
        .getByRole('button', { name: 'Save connection', exact: true })
        .click();
    await page.getByText('Manual webhook setup', { exact: true }).click();
    const secret = 'e2e-webhook-secret-0123456789abcdef';
    await page.getByLabel('Webhook secret (optional)').fill(secret);
    await page
        .getByRole('button', {
            name: 'Save manual webhook settings',
            exact: true,
        })
        .click();
    await expect(page.getByLabel('Webhook secret (optional)')).toHaveValue('');
    await expect(page.getByLabel('Webhook secret (optional)')).toHaveAttribute(
        'placeholder',
        'Configured — leave blank to keep',
    );
    await expect(page.getByLabel('Webhook delivery URL')).toHaveValue(
        /\/webhooks\/woocommerce\/\d+$/,
    );
    await page.reload();
    await page.getByText('Manual webhook setup', { exact: true }).click();
    await expect(page.getByLabel('Webhook secret (optional)')).toHaveValue('');
    for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await page
            .getByLabel('Webhook secret (optional)')
            .scrollIntoViewIfNeeded();
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > window.innerWidth,
        );
        expect(overflow).toBe(false);
        await page.screenshot({
            path: `/tmp/woocommerce-webhook-${width}.png`,
            fullPage: true,
        });
    }
    await errors.assertNone();
});
