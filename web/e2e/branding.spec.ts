import { expect, test } from '@playwright/test';
import { signIn } from './support';

test('brand name, primary colour, and favicon match the public branding', async ({
    page,
    request,
}) => {
    // Read-only on purpose: a write would recolor the shared dev brand, and Reset
    // would discard a customisation that was already there.
    const response = await request.get('/api/v1/branding');
    expect(response.ok()).toBeTruthy();
    const brand = (await response.json()).data as {
        name: string;
        primary_color: string;
        favicon_url: string;
    };

    await page.goto('/signin');
    await expect(page.getByRole('heading', { level: 1, name: brand.name })).toBeVisible();
    const icon = page.locator('link[rel="icon"]').first();
    await expect(icon).toHaveAttribute('href', /branding\/favicon/);
    const href = await icon.getAttribute('href');
    expect(href).toContain(new URL(brand.favicon_url).search || 'v=');

    await signIn(page, 'alice');
    await expect(page.getByText(brand.name, { exact: true }).first()).toBeVisible();

    const primary = await page.evaluate(() => {
        const name = '--ant-color-primary';
        const nodes = [
            document.documentElement,
            document.body,
            ...document.querySelectorAll('body *'),
        ];
        for (const node of nodes) {
            const value = getComputedStyle(node).getPropertyValue(name).trim();
            if (value) return value;
        }
        const sider = document.querySelector('.ant-layout-sider');
        return sider ? getComputedStyle(sider).backgroundColor : '';
    });
    expect(normalizeColor(primary)).toBe(brand.primary_color.toLowerCase());
});

function normalizeColor(value: string): string {
    const raw = value.trim().toLowerCase();
    if (raw.startsWith('#')) return raw;
    const match = raw.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (!match) return raw;
    const hex = (channel: string) => Number(channel).toString(16).padStart(2, '0');
    return `#${hex(match[1])}${hex(match[2])}${hex(match[3])}`;
}
