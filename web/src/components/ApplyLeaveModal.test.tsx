// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApplyLeaveModal } from './ApplyLeaveModal';

const TYPE_ID = '01J00000000000000000000000';

interface Call {
    url: string;
    method: string;
    headers: Headers;
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
        const call = { url: req.url, method: req.method, headers: req.headers, body };
        calls.push(call);
        const path = new URL(req.url).pathname;
        if (path.endsWith('/leave-types')) {
            return json(200, {
                data: [
                    {
                        id: TYPE_ID,
                        code: 'CL',
                        name: 'Casual',
                        annual_quota: 12,
                        tracks_balance: true,
                        carry_forward_cap: 0,
                        max_balance: null,
                        allow_half_day: true,
                        max_backdate_days: 365,
                        is_active: true,
                    },
                ],
                meta: {},
                links: {},
            });
        }
        if (path.endsWith('/leave-requests/preview')) {
            return json(200, {
                data: {
                    days: 2,
                    leave_year: 2026,
                    tracks_balance: true,
                    available_before: 10,
                    available_after: 8,
                },
                meta: {},
                links: {},
            });
        }
        if (path.endsWith('/leave-requests') && req.method === 'POST') {
            return json(422, {
                type: 'urn:lms:problem:validation-failed',
                title: 'The request is invalid',
                status: 422,
                code: 'VALIDATION_FAILED',
                detail: 'Check the form',
                errors: {
                    start_date: ['Outside the leave year'],
                    reason: ['Server says no'],
                },
            });
        }
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
                <ApplyLeaveModal open onClose={() => {}} />
            </App>
        </QueryClientProvider>,
    );
}

describe('ApplyLeaveModal', () => {
    it('previews the day count, sends an idempotency key, and maps problem errors onto fields', async () => {
        renderModal();
        expect(await screen.findByRole('radio', { name: 'Casual' })).toBeTruthy();

        // The open month is "now"; the 10th and 12th are in that panel (backdate allows them).
        const start = dayjs().date(10).format('YYYY-MM-DD');
        const end = dayjs().date(12).format('YYYY-MM-DD');
        const from = await screen.findByPlaceholderText('From');
        fireEvent.mouseDown(from);
        fireEvent.click(from);
        (from as HTMLInputElement).focus();
        fireEvent.click(await screen.findByTitle(start));
        fireEvent.click(screen.getByTitle(end));
        fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), {
            target: { value: 'Family trip' },
        });

        await waitFor(() => expect(screen.getByTestId('preview').textContent).toContain('2 days'), {
            timeout: 3000,
        });
        expect(screen.queryByRole('spinbutton', { name: 'Hours' })).toBeNull();

        const preview = calls.find((c) => c.url.includes('/leave-requests/preview'));
        expect(preview?.method).toBe('POST');
        expect(preview?.body).toMatchObject({
            leave_type_id: TYPE_ID,
            start_date: start,
            end_date: end,
            day_part: 'full',
            reason: 'Family trip',
        });

        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        await waitFor(() => expect(screen.getByText('Outside the leave year')).toBeTruthy());
        expect(screen.getByText('Server says no')).toBeTruthy();

        const submit = calls.find(
            (c) => c.method === 'POST' && new URL(c.url).pathname.endsWith('/leave-requests'),
        );
        expect(submit?.headers.get('Idempotency-Key')).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
        );
        expect(submit?.body).toMatchObject({
            leave_type_id: TYPE_ID,
            start_date: start,
            end_date: end,
            day_part: 'full',
            reason: 'Family trip',
        });
        expect(submit?.body).not.toHaveProperty('hours');
    });

    it('shows hours on a single day and hides them for a half day', async () => {
        renderModal();
        expect(await screen.findByRole('radio', { name: 'Casual' })).toBeTruthy();
        expect(screen.queryByRole('spinbutton', { name: 'Hours' })).toBeNull();

        const day = dayjs().date(10).format('YYYY-MM-DD');
        const from = await screen.findByPlaceholderText('From');
        fireEvent.mouseDown(from);
        fireEvent.click(from);
        (from as HTMLInputElement).focus();
        fireEvent.click(await screen.findByTitle(day));
        fireEvent.click(screen.getByTitle(day));

        expect(await screen.findByRole('spinbutton', { name: 'Hours' })).toBeTruthy();

        const half = screen.getByRole('checkbox', { name: 'Half day' });
        fireEvent.click(half);
        fireEvent.change(half, { target: { checked: true } });
        await waitFor(() => expect(screen.queryByRole('spinbutton', { name: 'Hours' })).toBeNull());

        fireEvent.click(half);
        fireEvent.change(half, { target: { checked: false } });
        expect(await screen.findByRole('spinbutton', { name: 'Hours' })).toBeTruthy();
    });
});
