// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UserMenu } from './UserMenu';

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        async () => new Response(JSON.stringify({ title: 'Not Found' }), { status: 404 }),
    );
});

describe('UserMenu', () => {
    it('links Profile next to Sign out', async () => {
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <App>
                    <UserMenu name="Alice" mobile={false} />
                </App>
            </QueryClientProvider>,
        );
        fireEvent.click(screen.getByRole('button', { name: /User menu for Alice/ }));
        const profile = await screen.findByRole('link', { name: 'Profile' });
        expect(profile.getAttribute('href')).toBe('/profile');
        expect(screen.getByRole('menuitem', { name: /Sign out/ })).toBeTruthy();
    });
});
