import { expect, test, type Page } from '@playwright/test';
import { seriousViolations, signIn } from './support';

test('axe finds no serious or critical violations on shipped pages', async ({ page }) => {
    test.setTimeout(300_000);
    const violations: string[] = [];

    await signIn(page, 'alice');
    await check(page, violations, '/', 'Dashboard');
    await check(page, violations, '/leave-requests', 'Leaves');
    await page.goto('/leave-requests/new');
    await expect(page.getByRole('heading', { level: 1, name: 'Leaves' })).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Apply Leave' })).toBeVisible();
    violations.push(...(await seriousViolations(page, '/leave-requests/new')));

    await signIn(page, 'bob');
    await check(page, violations, '/approvals', 'Approvals');

    await signIn(page, 'carol');
    await check(page, violations, '/holidays', 'Holidays');
    await check(page, violations, '/admin/users', 'Users');
    await check(page, violations, '/admin/departments', 'Departments');
    await check(page, violations, '/admin/leave-types', 'Leave types');
    await check(page, violations, '/admin/holidays', 'Holiday calendar');
    await check(page, violations, '/admin/reports', 'Reports');
    await check(page, violations, '/admin/settings/working-week', 'Working week');

    await signIn(page, 'dave');
    await check(page, violations, '/admin/settings/branding', 'Branding');
    await check(page, violations, '/admin/settings/modules', 'Modules');
    await check(page, violations, '/admin/identity-users', 'Identity users');

    expect(violations, violations.join('\n')).toEqual([]);
});

async function check(
    page: Page,
    violations: string[],
    path: string,
    heading: string,
): Promise<void> {
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
    violations.push(...(await seriousViolations(page, path)));
}
