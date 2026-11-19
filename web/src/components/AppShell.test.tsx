// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppShell } from './AppShell';

vi.mock('next/navigation', () => ({ usePathname: () => '/' }));

const brand = { name: 'Acme Leave', logo_url: '/branding/logo', modules: ['reports'] };

describe('AppShell', () => {
    it('renders the brand and only the nav links the user may see', () => {
        render(
            <AppShell user={{ name: 'Alice', role: 'employee', is_admin: false }} brand={brand}>
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
            <AppShell user={{ name: 'Bob', role: 'manager', is_admin: false }} brand={brand}>
                <p>content</p>
            </AppShell>,
        );
        expect(screen.getByRole('link', { name: 'Approvals' })).toBeTruthy();
    });
});
