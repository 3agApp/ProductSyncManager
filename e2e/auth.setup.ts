import { expect, test as setup } from '@playwright/test';

const USER = { email: 'test@example.com', password: 'password' };

/**
 * Sign in once and keep the session for every other test.
 */
setup('sign in', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email address').fill(USER.email);
    await page.getByLabel('Password', { exact: true }).fill(USER.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/dashboard/);

    await page.context().storageState({ path: 'e2e/.auth/user.json' });
});
