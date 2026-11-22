// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkingWeekForm, WorkingWeekView } from './WorkingWeekView';

interface Call {
    url: string;
    method: string;
    body: unknown;
}

const calls: Call[] = [];

function json(status: number, body: unknown) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    calls.length = 0;
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        let body: unknown;
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            const text = await req.text();
            body = text ? JSON.parse(text) : undefined;
        }
        calls.push({ url: req.url, method: req.method, body });
        return json(200, {
            data: { weekend_days: [6, 7], overridden: true },
            meta: {},
            links: {},
        });
    });
});

function renderForm(initialDays: number[]) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <App>
                <WorkingWeekForm initialDays={initialDays} overridden={false} />
            </App>
        </QueryClientProvider>,
    );
}

describe('WorkingWeekForm', () => {
    it('saves an empty weekend as a 7-day working week', async () => {
        renderForm([]);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(calls).toHaveLength(1));
        expect(calls[0]?.method).toBe('PATCH');
        expect(calls[0]?.body).toEqual({ weekend_days: [] });
    });

    it('rejects all seven days before saving', async () => {
        renderForm([1, 2, 3, 4, 5, 6, 7]);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Leave at least one working day.')).toBeTruthy();
        expect(calls).toHaveLength(0);
    });

    it('rejects a duplicate weekday before saving', async () => {
        renderForm([6, 6]);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Each weekday can only be selected once.')).toBeTruthy();
        expect(calls).toHaveLength(0);
    });

    it('saves a valid weekend', async () => {
        renderForm([6, 7]);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(calls).toHaveLength(1));
        expect(calls[0]?.method).toBe('PATCH');
        expect(calls[0]?.url).toContain('/bff/api/settings/working-week');
        expect(calls[0]?.body).toEqual({ weekend_days: [6, 7] });
    });
});

describe('WorkingWeekView', () => {
    it('shows the problem when the working week cannot be loaded', async () => {
        vi.stubGlobal('fetch', async () =>
            json(404, {
                type: 'urn:lms:problem:not-found',
                title: 'Not Found',
                status: 404,
                code: 'NOT_FOUND',
                detail: 'Working week is not available yet.',
            }),
        );
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <App>
                    <WorkingWeekView />
                </App>
            </QueryClientProvider>,
        );
        expect(await screen.findByText('Working week is not available yet.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });
});
