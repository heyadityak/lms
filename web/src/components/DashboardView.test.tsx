// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { App } from 'antd';
import dayjs from 'dayjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Schemas } from '@/lib/api/client';
import * as timeoff from '@/lib/timeoff';
import { AppProviders, type ShellUser } from './providers';
import { DashboardView } from './DashboardView';

const push = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ push, replace: vi.fn() }) }));

interface Call {
    url: string;
    method: string;
}

const calls: Call[] = [];

const balance = {
    data: [
        {
            leave_type: { id: '01J00000000000000000000000', code: 'CL', name: 'Casual' },
            year: 2026,
            entitled: 12,
            used: 1,
            pending: 0,
            balance: 11,
            available: 11,
        },
    ],
    meta: { year: 2026 },
    links: {},
};

function json(body: unknown) {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    calls.length = 0;
    push.mockReset();
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        calls.push({ url: req.url, method: req.method });
        const path = new URL(req.url).pathname;
        if (path.endsWith('/me/balances')) return json(balance);
        if (path.endsWith('/approvals')) {
            return json({
                data: [],
                meta: { current_page: 1, per_page: 1, total: 4, last_page: 4 },
                links: {},
            });
        }
        if (path.endsWith('/leave-requests')) {
            return json({
                data: [],
                meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 },
                links: {},
            });
        }
        if (path.endsWith('/holidays')) {
            return json({
                data: [
                    { id: '01J00000000000000000000001', date: '2099-01-01', name: 'Future Day' },
                ],
                meta: {},
                links: {},
            });
        }
        if (path.endsWith('/settings/working-week')) {
            return json({ data: { weekend_days: [5], overridden: true }, meta: {}, links: {} });
        }
        return new Response(JSON.stringify({ title: 'unexpected', status: 500 }), { status: 500 });
    });
});

function renderDash(role: Schemas['Role'], modules: string[]) {
    const user: ShellUser = {
        id: '01J0000000000000000000000A',
        name: 'Ada',
        role,
        is_admin: false,
    };
    return render(
        <App>
            <AppProviders user={user} modules={modules}>
                <DashboardView />
            </AppProviders>
        </App>,
    );
}

const paths = () => calls.map((c) => new URL(c.url).pathname);

describe('DashboardView', () => {
    it('hides Team Time Off and the pending-approvals stat from an employee', async () => {
        renderDash('employee', ['holidays']);
        expect(screen.queryByRole('region', { name: 'Team Time Off' })).toBeNull();
        expect(screen.queryByText('Pending approvals')).toBeNull();
        expect(screen.getByText('Available Leaves')).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Next Holidays' })).toBeTruthy();
        await screen.findByText('11');
        expect(paths().some((p) => p.endsWith('/approvals') || p.endsWith('/leave-requests'))).toBe(
            false,
        );
        expect(paths().some((p) => p.includes('working-week'))).toBe(false);
        expect(paths().some((p) => p.endsWith('/holidays'))).toBe(true);
    });

    it('hides Next Holidays when the module is off', async () => {
        renderDash('employee', []);
        expect(screen.queryByRole('region', { name: 'Next Holidays' })).toBeNull();
        await screen.findByText('11');
        expect(paths().some((p) => p.endsWith('/holidays'))).toBe(false);
    });

    it('shows the pending count and team window for a manager', async () => {
        const grouped = vi.spyOn(timeoff, 'groupTimeOff');
        renderDash('manager', []);
        expect(screen.getByRole('region', { name: 'Team Time Off' })).toBeTruthy();
        expect(screen.getByText('Pending approvals')).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Next Holidays' })).toBeNull();
        await screen.findByText('4');
        await screen.findByText('Nobody is scheduled off');

        const team = calls.find((c) => new URL(c.url).pathname.endsWith('/leave-requests'));
        expect(team).toBeTruthy();
        const q = new URL(team!.url).searchParams;
        expect(q.get('filter[status]')).toBe('approved,pending');
        expect(q.get('filter[from]')).toBe(dayjs().format('YYYY-MM-DD'));
        expect(q.get('filter[to]')).toBe(dayjs().add(13, 'day').format('YYYY-MM-DD'));
        expect(q.get('include')).toBe('user');
        expect(q.get('page[size]')).toBe('100');
        await vi.waitFor(() => {
            const weekends = grouped.mock.calls.map((call) => call[3]);
            expect(weekends.at(-1)).toEqual([5]);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Apply Leave' }));
        expect(push).toHaveBeenCalledWith('/leave-requests/new');
    });
});
