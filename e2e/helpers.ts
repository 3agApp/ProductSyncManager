import type { Page, TestInfo } from '@playwright/test';
import { expect } from '@playwright/test';

/** The shop the seeder gives the test user. */
export const SHOP = 'test-users-shop';

/**
 * Fail the test if the page logs an error or the error boundary catches one.
 *
 * A React component that throws while rendering unmounts the whole tree and
 * leaves a blank page, which is easy to miss when only asserting on the bits
 * that did render. Watching the console catches it wherever it happens.
 */
export function watchForErrors(page: Page, testInfo: TestInfo) {
    const errors: string[] = [];

    page.on('console', (message) => {
        if (message.type() !== 'error') {
            return;
        }

        const text = message.text();

        // Images in the seeded catalog point at a store that is not running
        // here, and a broken image is not a broken page.
        if (/Failed to load resource/i.test(text)) {
            return;
        }

        errors.push(text);
    });

    page.on('pageerror', (error) => errors.push(String(error)));

    return {
        async assertNone() {
            // Attach whatever was collected so a failure carries the detail.
            if (errors.length > 0) {
                await testInfo.attach('console-errors', {
                    body: errors.join('\n'),
                });
            }

            await expect(
                page.getByTestId('error-boundary'),
                'the error boundary caught a render error',
            ).toHaveCount(0);

            expect(errors, 'the page logged errors').toEqual([]);
        },
    };
}

/**
 * Open a shadcn select and choose one of its options.
 */
export async function chooseFromSelect(
    page: Page,
    label: string,
    option: string,
) {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}
