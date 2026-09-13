import { expect, test } from '@playwright/test';
import { chooseFromSelect, SHOP, watchForErrors } from './helpers';

test.use({ timezoneId: 'Asia/Dhaka' });

test('schedule explains each frequency, saves minute choices, and preserves an explicit timezone', async ({
    page,
}, testInfo) => {
    const errors = watchForErrors(page, testInfo);
    await page.goto(`/settings/shops/${SHOP}/sync`);
    const form = page.getByTestId('schedule-form');
    const preview = page.getByTestId('schedule-preview');
    await expect(form).toContainText('Asia/Dhaka (your timezone)');
    await expect(preview).toContainText('Scheduled sync is off');
    await page.getByTestId('schedule-enabled').check();
    await expect(preview).toContainText('Every day at 2:00 AM.');
    await chooseFromSelect(page, 'How often?', 'Hourly');
    await expect(page.getByTestId('schedule-time')).toHaveCount(0);
    await chooseFromSelect(
        page,
        'When during each hour?',
        '15 minutes past (:15)',
    );
    await expect(preview).toContainText(
        'Every hour at 15 minutes past the hour.',
    );
    await page.getByTestId('schedule-save').click();
    await expect(page.getByTestId('schedule-next-run')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('schedule-minute')).toHaveText(
        '15 minutes past (:15)',
    );
    await chooseFromSelect(page, 'How often?', 'Every 6 hours');
    await page.getByTestId('schedule-time').fill('14:30');
    await expect(preview).toContainText(
        'Every day at 2:30 AM, 8:30 AM, 2:30 PM, 8:30 PM.',
    );
    await expect(page.getByTestId('schedule-next-run')).toHaveCount(0);
    await chooseFromSelect(page, 'How often?', 'Weekly');
    await chooseFromSelect(page, 'On which day?', 'Friday');
    await page.getByTestId('schedule-time').fill('03:45');
    await expect(preview).toContainText('Every Friday at 3:45 AM.');
    await page
        .getByRole('button', { name: 'Change timezone', exact: true })
        .click();
    await chooseFromSelect(page, 'Timezone', 'Europe/London');
    await expect(preview).toContainText('Times shown in Europe/London.');
    await page.getByTestId('schedule-save').click();
    await expect(page.getByTestId('schedule-next-run')).toBeVisible();
    await page.reload();
    await expect(form).toContainText('Europe/London');
    await expect(preview).toContainText('Every Friday at 3:45 AM.');
    await expect(preview).not.toContainText('Save to apply');
    for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await form.scrollIntoViewIfNeeded();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth > window.innerWidth,
            ),
        ).toBe(false);
        await form.screenshot({ path: `/tmp/sync-schedule-${width}.png` });
    }
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await form.screenshot({ path: '/tmp/sync-schedule-dark.png' });
    await page.getByTestId('schedule-enabled').uncheck();
    await expect(preview).toContainText('Scheduled sync will be paused');
    await page.getByTestId('schedule-save').click();
    await page.reload();
    await expect(page.getByTestId('schedule-enabled')).not.toBeChecked();
    await expect(preview).toContainText('Scheduled sync is off');
    await expect(page.getByTestId('schedule-next-run')).toHaveCount(0);
    await errors.assertNone();
});
