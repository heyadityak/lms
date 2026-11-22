import { expect, test, type Page } from '@playwright/test';
import { signIn } from './support';

test('carol sees the departments table', async ({ page }) => {
    await signIn(page, 'carol');
    await expectHealthy(page, '/admin/departments', 'Departments');
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Code' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Name' })).toBeVisible();
});

test('carol sees the locations page', async ({ page }) => {
    await signIn(page, 'carol');
    await expectHealthy(page, '/admin/locations', 'Locations');
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add location' })).toBeVisible();
});

test('carol sees the audit log page', async ({ page }) => {
    await signIn(page, 'carol');
    await expectHealthy(page, '/admin/audit-logs', 'Audit log');
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Subject' })).toBeVisible();
});

test('bob sees the approvals heading', async ({ page }) => {
    await signIn(page, 'bob');
    await expectHealthy(page, '/approvals', 'Approvals');
});

/**
 * The page answers, stays on its URL, and shows its heading.
 * Next.js keeps an empty route announcer (`role="alert"`); that is not an error boundary.
 */
async function expectHealthy(page: Page, path: string, heading: string): Promise<void> {
    const response = await page.goto(path);
    expect(response?.status() ?? 0, `${path} HTTP status`).toBeLessThan(500);
    await expect(page).toHaveURL(new RegExp(`${path.replaceAll('/', '\\/')}\\/?$`));
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
    await expect(page.getByText('Application error')).toHaveCount(0);
    await expect(page.getByText('Something went wrong')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: '500' })).toHaveCount(0);
}
