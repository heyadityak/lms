import { describe, expect, it } from 'vitest';
import { navItems, selectedKey, type NavItem } from './nav';

const ALL = ['holidays', 'reports', 'api', 'notifications', 'user_management'];
const keys = (items: NavItem[]) =>
    items.flatMap((i) => [i.key, ...(i.children ?? []).map((c) => c.key)]);

describe('navItems', () => {
    it('shows an employee only the core items and enabled modules', () => {
        expect(keys(navItems({ role: 'employee', is_admin: false }, ALL))).toEqual([
            '/',
            '/leave-requests',
            '/holidays',
        ]);
    });

    it('adds approvals for managers', () => {
        expect(keys(navItems({ role: 'manager', is_admin: false }, ALL))).toContain('/approvals');
    });

    it('gives hr_admin the HR admin items but not the admin ones', () => {
        const k = keys(navItems({ role: 'hr_admin', is_admin: false }, ALL));
        expect(k).toEqual(expect.arrayContaining(['/approvals', '/admin/users', '/admin/reports']));
        expect(k).not.toContain('/admin/settings/branding');
    });

    it('gives an employee with the additive admin role only the admin items', () => {
        const k = keys(navItems({ role: 'employee', is_admin: true }, ALL));
        expect(k).toEqual(
            expect.arrayContaining(['/admin/settings/branding', '/admin/identity-users']),
        );
        expect(k).not.toContain('/admin/users');
        expect(k).not.toContain('/approvals');
    });

    it('hides items of disabled modules', () => {
        const k = keys(navItems({ role: 'hr_admin', is_admin: true }, ['api']));
        for (const hidden of [
            '/holidays',
            '/admin/holidays',
            '/admin/reports',
            '/admin/identity-users',
        ]) {
            expect(k).not.toContain(hidden);
        }
    });
});

describe('selectedKey', () => {
    const items = navItems({ role: 'hr_admin', is_admin: true }, ALL);
    it('picks the longest matching prefix', () => {
        expect(selectedKey(items, '/')).toBe('/');
        expect(selectedKey(items, '/leave-requests/01ABC')).toBe('/leave-requests');
        expect(selectedKey(items, '/admin/settings/branding')).toBe('/admin/settings/branding');
    });
});
