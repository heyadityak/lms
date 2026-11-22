import { expect, test } from '@playwright/test';
import { signIn } from './support';

test('holidays off hides the nav link and the route is 404', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page, 'dave');
    const toggle = page.getByRole('switch', { name: 'Holiday calendar' });
    try {
        await page.goto('/admin/settings/modules');
        if (!(await toggle.isChecked())) {
            await toggle.click();
            await expect(toggle).toBeChecked();
        }
        await toggle.click();
        await expect(toggle).not.toBeChecked();
        await expect(page.getByText('Holiday calendar disabled')).toBeVisible();

        await page.goto('/');
        await expect(
            page
                .getByRole('navigation', { name: 'Main navigation' })
                .getByRole('link', { name: 'Holidays' }),
        ).toHaveCount(0);

        const response = await page.goto('/holidays');
        expect(response?.status()).toBe(404);
        await expect(page.getByRole('heading', { name: '404' })).toBeVisible();
    } finally {
        const restored = await page.request.patch('/bff/api/modules/holidays', {
            data: { enabled: true },
            headers: { Origin: 'http://localhost:8080', Accept: 'application/json' },
        });
        expect(restored.ok(), await restored.text()).toBeTruthy();
    }
});
