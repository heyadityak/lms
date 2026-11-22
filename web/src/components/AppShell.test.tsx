// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppShell } from './AppShell';

const path = vi.hoisted(() => ({ value: '/' }));

vi.mock('next/navigation', () => ({ usePathname: () => path.value }));

const brand = {
    name: 'Acme Leave',
    logo_url: '/branding/logo',
    primary_color: '#3A5FBE',
    primary_contrast: '#FFFFFF',
    modules: ['reports'],
};

describe('AppShell', () => {
    beforeEach(() => {
        path.value = '/';
    });

    it('renders the brand and only the nav links the user may see', () => {
        render(
            <AppShell
                user={{
                    id: '01J0000000000000000000000A',
                    name: 'Alice',
                    role: 'employee',
                    is_admin: false,
                }}
                brand={brand}
            >
                <p>content</p>
            </AppShell>,
        );
        expect(screen.getByRole('img', { name: 'Acme Leave' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Leaves' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Approvals' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Holidays' })).toBeNull();
        expect(screen.getByRole('button', { name: 'User menu for Alice' })).toBeTruthy();
    });

    it('shows approvals to a manager', () => {
        render(
            <AppShell
                user={{
                    id: '01J0000000000000000000000B',
                    name: 'Bob',
                    role: 'manager',
                    is_admin: false,
                }}
                brand={brand}
            >
                <p>content</p>
            </AppShell>,
        );
        expect(screen.getByRole('link', { name: 'Approvals' })).toBeTruthy();
    });

    it('lists the HR admin pages from the shell', () => {
        path.value = '/admin/users';
        render(
            <AppShell
                user={{
                    id: '01J0000000000000000000000C',
                    name: 'Carol',
                    role: 'hr_admin',
                    is_admin: false,
                }}
                brand={{ ...brand, modules: ['holidays', 'reports'] }}
            >
                <p>content</p>
            </AppShell>,
        );
        const users = screen.getByRole('link', { name: 'Users' });
        expect(users.getAttribute('href')).toBe('/admin/users');
        expect(users.getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('link', { name: 'Working week' }).getAttribute('href')).toBe(
            '/admin/settings/working-week',
        );
        expect(screen.getByRole('link', { name: 'Holiday calendar' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Branding' })).toBeNull();
    });

    it('opens admin sections from the Admin menu', async () => {
        render(
            <AppShell
                user={{
                    id: '01J0000000000000000000000C',
                    name: 'Carol',
                    role: 'hr_admin',
                    is_admin: false,
                }}
                brand={{ ...brand, modules: ['holidays', 'reports'] }}
            >
                <p>content</p>
            </AppShell>,
        );
        expect(screen.queryByRole('link', { name: 'Departments' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Admin' }));
        const departments = await screen.findByRole('link', { name: 'Departments' });
        expect(departments.getAttribute('href')).toBe('/admin/departments');
    });
});
