// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppProviders, type ShellUser } from './providers';
import { LeavesView } from './LeavesView';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));

const page = (total: number) => ({
    data: [],
    meta: { current_page: 1, per_page: 10, total, last_page: 1 },
    links: {},
});

const leaveType = (id: string, code: string, name: string, tracks_balance: boolean) => ({
    id,
    code,
    name,
    annual_quota: 12,
    tracks_balance,
    carry_forward_cap: 0,
    max_balance: null,
    allow_half_day: true,
    max_backdate_days: 0,
    is_active: true,
});

const balance = (id: string, code: string, name: string, available: number) => ({
    leave_type: { id, code, name },
    year: 2026,
    entitled: available,
    used: 0,
    pending: 0,
    balance: available,
    available,
});

function json(body: unknown) {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        const path = new URL(req.url).pathname;
        if (path.endsWith('/me/balances')) {
            return json({
                data: [
                    balance('01J00000000000000000000001', 'CL', 'Casual', 8),
                    balance('01J00000000000000000000002', 'SL', 'Sick', 3),
                    balance('01J00000000000000000000003', 'LWP', 'Unpaid', 99),
                ],
                meta: { year: 2026 },
                links: {},
            });
        }
        if (path.endsWith('/leave-types')) {
            return json({
                data: [
                    leaveType('01J00000000000000000000001', 'CL', 'Casual', true),
                    leaveType('01J00000000000000000000002', 'SL', 'Sick', true),
                    leaveType('01J00000000000000000000003', 'LWP', 'Unpaid', false),
                ],
                meta: {},
                links: {},
            });
        }
        if (path.endsWith('/me/leave-requests')) return json(page(2));
        if (path.endsWith('/leave-requests')) return json(page(0));
        return new Response(JSON.stringify({ title: 'unexpected', status: 500 }), { status: 500 });
    });
});

describe('LeavesView stats', () => {
    it('shows one available card per tracked type plus the pending count', async () => {
        const user: ShellUser = {
            id: '01J0000000000000000000000A',
            name: 'Ada',
            role: 'employee',
            is_admin: false,
        };
        render(
            <App>
                <AppProviders user={user} modules={[]}>
                    <LeavesView />
                </AppProviders>
            </App>,
        );

        await waitFor(() => {
            expect(screen.getByText('Casual')).toBeTruthy();
            expect(screen.queryByText('Unpaid')).toBeNull();
        });
        expect(screen.getByText('Sick')).toBeTruthy();
        expect(screen.getByText('8')).toBeTruthy();
        expect(screen.getByText('3')).toBeTruthy();
        expect(screen.getByText('Pending leave requests')).toBeTruthy();
        expect(screen.getByText('2')).toBeTruthy();
        expect(screen.queryByText('Unpaid')).toBeNull();
        expect(screen.queryByText('99')).toBeNull();
        expect(screen.queryByText('Available Leaves')).toBeNull();
        expect(screen.queryByText('Entitled this year')).toBeNull();
        expect(screen.queryByText('Used this year')).toBeNull();
    });
});
