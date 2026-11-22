import { expect, test, type Page } from '@playwright/test';
import { dayLabel, signIn, signOut, statValue, workingPairs } from './support';

test('alice applies, bob approves, and her available balance drops', async ({ page }) => {
    test.setTimeout(180_000);
    const start = new Date();
    start.setDate(start.getDate() + 1);
    const windows = workingPairs(start, 12);

    await signIn(page, 'alice');
    await page.goto('/leave-requests');
    const before = await statValue(page, 'Earned Leave');

    const chosen = await applyFirstOpenWindow(page, windows);
    expect(chosen).not.toBeNull();
    const [from, to] = chosen!;

    await signOut(page);
    await signIn(page, 'bob');
    await page.goto('/approvals');
    const row = page.getByRole('row', { name: new RegExp(dayLabel(from)) });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Approve' }).click();
    await page.locator('.ant-popconfirm').getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByText("Approved Alice Employee's leave")).toBeVisible();
    await expect(row).toHaveCount(0);

    await signOut(page);
    await signIn(page, 'alice');
    await page.goto('/leave-requests');
    const after = await statValue(page, 'Earned Leave');
    expect(after).toBe(before - 2);

    // Put the shared dev balance back. The drop is already asserted.
    await page.getByRole('button', { name: `Cancel Earned Leave from ${dayLabel(from)}` }).click();
    await page.getByRole('button', { name: 'Cancel request' }).click();
    await expect(page.getByText('Leave request cancelled')).toBeVisible();
    expect(to).not.toBe('');
});

async function applyFirstOpenWindow(
    page: Page,
    windows: Array<[string, string]>,
): Promise<[string, string] | null> {
    for (const [from, to] of windows) {
        await page.goto('/leave-requests/new');
        const dialog = page.getByRole('dialog', { name: 'Apply Leave' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('radio', { name: 'Earned Leave' }).check();
        const start = dialog.getByPlaceholder('From');
        await start.click();
        await start.fill(from);
        await dialog.getByPlaceholder('To').fill(to);
        await dialog.getByPlaceholder('To').press('Enter');
        await dialog.getByLabel('Reason').fill('Family trip for the regression');
        await dialog.getByRole('button', { name: 'Apply', exact: true }).click();

        const submitted = page.getByText('Leave request submitted');
        const failed = dialog.getByRole('alert');
        await expect(submitted.or(failed)).toBeVisible();
        if (await submitted.isVisible()) return [from, to];
    }
    return null;
}
