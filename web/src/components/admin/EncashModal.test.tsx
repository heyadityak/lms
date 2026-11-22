// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EncashModal } from './EncashModal';

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
        const path = new URL(req.url).pathname;
        if (path.endsWith('/leave-types')) {
            return json(200, {
                data: [
                    {
                        id: '01J0000000000000000000000T',
                        code: 'CL',
                        name: 'Casual',
                        tracks_balance: true,
                        annual_quota: 12,
                        carry_forward_cap: 0,
                        max_balance: null,
                        allow_half_day: true,
                        max_backdate_days: 0,
                        sandwich: false,
                        accrual_frequency: 'yearly',
                        is_active: true,
                    },
                ],
                meta: {},
                links: {},
            });
        }
        if (req.method === 'POST')
            return json(201, { data: { id: '01JENCASH' }, meta: {}, links: {} });
        return json(500, { title: 'unexpected', status: 500, code: 'INTERNAL_ERROR' });
    });
});

function renderModal() {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <App>
                <EncashModal
                    userId="01J0000000000000000000000A"
                    userName="Alice"
                    onClose={() => {}}
                    onSaved={async () => {}}
                />
            </App>
        </QueryClientProvider>,
    );
}

describe('EncashModal', () => {
    it('requires a note before posting the encashment', async () => {
        renderModal();
        fireEvent.click(screen.getByRole('button', { name: 'Encash' }));
        expect(await screen.findByText('A note is required')).toBeTruthy();
        expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0);
    });
});
