// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SignInCard } from './SignInCard';

const props = {
    name: 'Acme Leave',
    logoUrl: '/branding/logo',
    primaryColor: '#3A5FBE',
    primaryContrast: '#FFFFFF',
};

describe('SignInCard', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('shows the brand welcome and username and password labels', () => {
        render(<SignInCard {...props} />);
        expect(screen.getByRole('heading', { level: 1, name: 'Acme Leave' })).toBeTruthy();
        expect(screen.getByText('Welcome')).toBeTruthy();
        expect(
            screen.getByText(/request leave, approve time off, and check your balances/i),
        ).toBeTruthy();
        expect(screen.getByLabelText('Username')).toBeTruthy();
        expect(screen.getByLabelText('Password')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Sign in with SSO' })).toBeNull();
        const brand = document.querySelector('.lms-welcome-brand') as HTMLElement;
        expect(brand.style.backgroundColor).toBe('rgb(58, 95, 190)');
        expect(brand.style.color).toBe('rgb(255, 255, 255)');
    });

    it('shows a form error for a rejected password and does not navigate', async () => {
        const href = window.location.href;
        const calls: { url: string; body: unknown }[] = [];
        vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
            calls.push({
                url: String(input),
                body: JSON.parse(String(init?.body ?? '')),
            });
            return Response.json(
                { url: 'https://keycloak.example/protocol/openid-connect/auth' },
                { status: 401 },
            );
        });
        render(<SignInCard {...props} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'alice' } });
        fireEvent.change(screen.getByLabelText('Password'), {
            target: { value: 'wrong-password' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByText('Those credentials were not accepted.')).toBeTruthy();
        expect(screen.getByLabelText('Username')).toBeTruthy();
        expect(screen.getByLabelText('Password')).toBeTruthy();
        expect(window.location.href).toBe(href);
        expect(calls).toEqual([
            { url: '/bff/login', body: { username: 'alice', password: 'wrong-password' } },
        ]);
    });

    it('shows the unavailable alert when sign-in cannot be completed', async () => {
        const href = window.location.href;
        vi.stubGlobal('fetch', async () => Response.json({ ok: false }, { status: 503 }));
        render(<SignInCard {...props} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'alice' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
        expect(await screen.findByText('Sign-in is unavailable. Try again shortly.')).toBeTruthy();
        expect(window.location.href).toBe(href);
    });
});
