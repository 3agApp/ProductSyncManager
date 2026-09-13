import { createServer } from 'node:http';
import type { Server } from 'node:http';
import { expect, test } from '@playwright/test';
import { SHOP, watchForErrors } from './helpers';

let wooServer: Server;
let storeUrl: string;
const hooks = new Map<number, Record<string, unknown>>();

test.beforeAll(async () => {
    wooServer = createServer(async (request, response) => {
        response.setHeader('Content-Type', 'application/json');
        if (request.method === 'GET') {
            response.end(JSON.stringify([...hooks.values()]));
            return;
        }
        if (
            Buffer.from(
                (request.headers.authorization ?? '').replace('Basic ', ''),
                'base64',
            )
                .toString()
                .startsWith('ck_readonly:')
        ) {
            response.statusCode = 401;
            response.end(
                JSON.stringify({
                    code: 'woocommerce_rest_authentication_error',
                    message:
                        'The API key provided does not have write permissions.',
                }),
            );
            return;
        }
        let body = '';
        for await (const chunk of request) body += chunk;
        const id =
            request.method === 'POST'
                ? hooks.size + 1
                : Number(request.url?.split('/').pop());
        const hook = { ...JSON.parse(body), id };
        hooks.set(id, hook);
        response.statusCode = request.method === 'POST' ? 201 : 200;
        response.end(JSON.stringify(hook));
    });
    await new Promise<void>((resolve) =>
        wooServer.listen(0, '127.0.0.1', resolve),
    );
    const address = wooServer.address();
    if (!address || typeof address === 'string')
        throw new Error('Missing test server address');
    storeUrl = `http://127.0.0.1:${address.port}`;
});

test.afterAll(async () => {
    await new Promise<void>((resolve, reject) =>
        wooServer.close((error) => (error ? reject(error) : resolve())),
    );
});

test('automatic setup reports permissions after clicking, then succeeds with a read/write key', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);
    await page.goto(`/settings/shops/${SHOP}/sync`);
    await page.getByLabel('Store address').fill(storeUrl);
    await page.getByLabel('Consumer key', { exact: true }).fill('ck_readonly');
    await page.getByLabel('Consumer secret', { exact: true }).fill('cs_test');
    await page.getByTestId('connection-save').click();
    const section = page.getByTestId('automatic-webhooks');
    await expect(page.getByTestId('webhook-setup-button')).toBeEnabled();
    await expect(page.getByTestId('webhook-setup-error')).toHaveCount(0);
    await page.getByTestId('webhook-setup-button').click();
    await expect(page.getByTestId('webhook-setup-error')).toContainText(
        'This API key does not have permission to manage webhooks.',
    );
    await expect(page.getByTestId('webhook-setup-error')).toContainText(
        'Read/Write',
    );
    await page.setViewportSize({ width: 390, height: 1000 });
    await section.screenshot({
        path: '/tmp/automatic-webhooks-permission.png',
    });
    await page.getByLabel('Consumer key', { exact: true }).fill('ck_write');
    await page.getByTestId('connection-save').click();
    await page.getByTestId('webhook-setup-button').click();
    await expect(page.getByTestId('webhook-setup-success')).toBeVisible();
    expect(hooks.size).toBe(4);
    await page.getByTestId('webhook-setup-button').click();
    await expect(page.getByTestId('webhook-setup-success')).toBeVisible();
    expect(hooks.size).toBe(4);
    for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth > window.innerWidth,
            ),
        ).toBe(false);
        await section.screenshot({
            path: `/tmp/automatic-webhooks-${width}.png`,
        });
    }
    await errors.assertNone();
});
