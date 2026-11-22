import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Dev realm password. DEV ONLY. */
export const PASSWORD = 'password';

const NAMES: Record<string, string> = {
    alice: 'Alice Employee',
    bob: 'Bob Manager',
    carol: 'Carol HR',
    dave: 'Dave Admin',
};

export async function signIn(page: Page, username: string): Promise<void> {
    const expected = NAMES[username] ?? username;
    // A fresh cookie jar so a Keycloak SSO session cannot skip the login form.
    await page.context().clearCookies();

    for (let attempt = 1; attempt <= 4; attempt++) {
        await page.goto('/signin');
        await page.getByRole('button', { name: 'Sign in with SSO' }).click();
        const usernameField = page.locator('#username');
        const unavailable = page.getByText('Sign-in is unavailable');
        await expect(usernameField.or(unavailable)).toBeVisible({ timeout: 20_000 });
        if (await unavailable.isVisible()) {
            await page.waitForTimeout(attempt * 2_000);
            continue;
        }

        await usernameField.fill(username);
        await page.locator('#password').fill(PASSWORD);
        await page.locator('#kc-login').click();
        await expect(page.getByRole('button', { name: `User menu for ${expected}` })).toBeVisible({
            timeout: 30_000,
        });
        return;
    }

    throw new Error(`Could not sign in as ${username}`);
}

export async function signOut(page: Page): Promise<void> {
    const menu = page.getByRole('button', { name: /User menu for/ });
    if (await menu.isVisible().catch(() => false)) {
        await menu.click();
        await page.getByRole('menuitem', { name: 'Sign out' }).click();
    }
    await page.goto('/signin');
    await expect(page.getByRole('button', { name: 'Sign in with SSO' })).toBeVisible();
}

/** Serious and critical axe violations, one line per rule, prefixed with the page. */
export async function seriousViolations(page: Page, path: string): Promise<string[]> {
    const results = await new AxeBuilder({ page }).analyze();
    return results.violations
        .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
        .map((violation) => {
            const target = violation.nodes[0]?.target.join(' ') ?? '';
            return `${path}: ${violation.id} (${violation.impact}) ${violation.help} [${target}]`;
        });
}

export async function statValue(page: Page, title: string): Promise<number> {
    const card = page.locator('.ant-card').filter({ hasText: title });
    await expect(card.first()).toBeVisible();
    const raw = (await card.first().locator('span').first().innerText()).trim();
    const value = Number(raw);
    expect(Number.isNaN(value), `expected a number in the ${title} card, got ${raw}`).toBe(false);
    return value;
}

/** Consecutive weekday pairs that are not the seeded fixed-date holidays. */
export function workingPairs(from: Date, count: number): Array<[string, string]> {
    const holidays = new Set([
        '2026-01-01',
        '2026-05-01',
        '2026-08-15',
        '2026-10-02',
        '2026-12-25',
        '2027-01-01',
    ]);
    const pairs: Array<[string, string]> = [];
    const cursor = new Date(from);
    cursor.setHours(12, 0, 0, 0);
    while (pairs.length < count) {
        const next = new Date(cursor);
        next.setDate(cursor.getDate() + 1);
        const a = iso(cursor);
        const b = iso(next);
        const weekday = (date: Date) => date.getDay() !== 0 && date.getDay() !== 6;
        if (weekday(cursor) && weekday(next) && !holidays.has(a) && !holidays.has(b)) {
            pairs.push([a, b]);
            cursor.setDate(cursor.getDate() + 2);
        } else {
            cursor.setDate(cursor.getDate() + 1);
        }
    }
    return pairs;
}

export function dayLabel(isoDate: string): string {
    const [, month, day] = isoDate.split('-');
    const year = isoDate.slice(0, 4);
    return `${day} ${MONTHS[Number(month) - 1]} ${year}`;
}

function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}
