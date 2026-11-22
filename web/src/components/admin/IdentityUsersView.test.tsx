// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { IdentityUsersView } from './IdentityUsersView';

interface Call {
    url: string;
    method: string;
    body: unknown;
    idempotencyKey: string | null;
}

const calls: Call[] = [];
let unavailable = false;

function json(status: number, body: unknown) {
    return new Response(JSON.stringify(body), {
        status,
        headers: {
            'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json',
        },
    });
}

const page = {
    data: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    links: {},
};

beforeEach(() => {
    calls.length = 0;
    unavailable = false;
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        let body: unknown;
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            const text = await req.text();
            body = text ? JSON.parse(text) : undefined;
        }
        calls.push({
            url: req.url,
            method: req.method,
            body,
            idempotencyKey: req.headers.get('idempotency-key'),
        });
        if (unavailable) {
            return json(503, {
                type: 'urn:lms:problem:idp-unavailable',
                title: 'Identity provider unavailable',
                status: 503,
                code: 'IDP_UNAVAILABLE',
                detail: 'Keycloak did not respond.',
                request_id: 'req-idp',
            });
        }
        if (req.method === 'POST') {
            return json(201, {
                data: {
                    id: '01J0000000000000000000000A',
                    keycloak_id: '00000000-0000-4000-8000-000000000001',
                    username: 'ada.lovelace',
                    email: 'ada@example.com',
                    first_name: 'Ada',
                    last_name: 'Lovelace',
                    enabled: true,
                    email_verified: false,
                    role: 'employee',
                    is_admin: false,
                    federated: false,
                    federation_link: null,
                    department: null,
                    manager: null,
                    date_of_joining: null,
                    employee_code: null,
                    designation: null,
                    employment_type: null,
                    is_active: true,
                },
                meta: {},
                links: {},
            });
        }
        return json(200, page);
    });
});

function renderView() {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <App>
                <IdentityUsersView currentUserId="01J0000000000000000000000Z" />
            </App>
        </QueryClientProvider>,
    );
}

describe('IdentityUsersView', () => {
    it('sends Idempotency-Key on create and does not include a password', async () => {
        renderView();
        fireEvent.click(await screen.findByRole('button', { name: 'Add user' }));
        fireEvent.change(await screen.findByRole('textbox', { name: 'Username' }), {
            target: { value: 'ada.lovelace' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), {
            target: { value: 'ada@example.com' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'First name' }), {
            target: { value: 'Ada' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Last name' }), {
            target: { value: 'Lovelace' },
        });
        expect(screen.queryByLabelText(/password/i)).toBeNull();
        expect(document.querySelector('input[type="password"]')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Create user' }));

        await waitFor(() => expect(calls.some((call) => call.method === 'POST')).toBe(true));
        const post = calls.find((call) => call.method === 'POST');
        expect(post?.url).toContain('/bff/api/admin/users');
        expect(post?.idempotencyKey).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
        );
        expect(post?.body).toMatchObject({
            username: 'ada.lovelace',
            email: 'ada@example.com',
            first_name: 'Ada',
            last_name: 'Lovelace',
        });
        expect(post?.body).not.toHaveProperty('password');
    });

    it('shows a full-page retry when the identity provider is unavailable', async () => {
        unavailable = true;
        renderView();
        expect(await screen.findByText(/Keycloak did not respond/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Retry' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Add user' })).toBeNull();
        expect(screen.queryByRole('table')).toBeNull();
    });
});
