// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Schemas } from '@/lib/api/client';
import { AppProviders, newQueryClient } from './providers';
import { ProfileView } from './ProfileView';

const USER_ID = '01J0000000000000000000000A';
const MANAGER_ID = '01J0000000000000000000000B';
const DEPT_ID = '01J0000000000000000000000D';
const LOCATION_ID = '01J0000000000000000000000L';

interface Call {
    url: string;
    method: string;
    body: unknown;
}

const calls: Call[] = [];
let failFields = false;

function user(role: Schemas['Role']) {
    return {
        id: USER_ID,
        name: role === 'hr_admin' ? 'Carol HR' : 'Alice Employee',
        email: 'alice@example.com',
        role,
        is_admin: false,
        department: { id: DEPT_ID, code: 'ENG', name: 'Engineering' },
        location: { id: LOCATION_ID, code: 'HQ', name: 'Head office' },
        manager: { id: MANAGER_ID, name: 'Bob Manager' },
        employee_code: 'E-100',
        designation: 'Engineer',
        employment_type: 'Full time',
        date_of_joining: '2024-06-03',
        is_active: true,
        avatar_url: null,
        last_login_at: null,
        created_at: '2024-06-03T00:00:00Z',
    };
}

function json(status: number, body: unknown) {
    return new Response(JSON.stringify(body), {
        status,
        headers: {
            'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json',
        },
    });
}

beforeEach(() => {
    calls.length = 0;
    failFields = false;
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        let body: unknown;
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            const text = await req.text();
            body = text ? JSON.parse(text) : undefined;
        }
        calls.push({ url: req.url, method: req.method, body });
        const path = new URL(req.url).pathname;
        if (req.method === 'GET' && path.endsWith('/me')) {
            return json(200, { data: user(currentRole), meta: {}, links: {} });
        }
        if (req.method === 'GET' && path.endsWith('/departments')) {
            return json(200, {
                data: [
                    {
                        id: DEPT_ID,
                        code: 'ENG',
                        name: 'Engineering',
                        users_count: 1,
                        leave_quotas: [],
                    },
                ],
                meta: {},
                links: {},
            });
        }
        if (req.method === 'GET' && path.endsWith('/locations')) {
            return json(200, {
                data: [{ id: LOCATION_ID, code: 'HQ', name: 'Head office' }],
                meta: {},
                links: {},
            });
        }
        if (req.method === 'GET' && path.endsWith('/users')) {
            return json(200, {
                data: [user('manager')],
                meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
                links: {},
            });
        }
        if (req.method === 'PATCH' && path.includes('/users/')) {
            if (failFields) {
                return json(422, {
                    type: 'urn:lms:problem:validation-failed',
                    title: 'The request is invalid',
                    status: 422,
                    code: 'VALIDATION_FAILED',
                    detail: 'Check the form',
                    errors: { employee_code: ['Employee code is already used'] },
                });
            }
            return json(200, { data: user('hr_admin'), meta: {}, links: {} });
        }
        return json(404, { title: 'Not Found', status: 404, code: 'NOT_FOUND' });
    });
});

let currentRole: Schemas['Role'] = 'employee';

function renderProfile(role: Schemas['Role']) {
    currentRole = role;
    const client = newQueryClient();
    client.setDefaultOptions({ queries: { retry: false } });
    return render(
        <App>
            <AppProviders
                user={{
                    id: USER_ID,
                    name: role === 'hr_admin' ? 'Carol HR' : 'Alice Employee',
                    role,
                    is_admin: false,
                }}
                modules={[]}
                client={client}
            >
                <ProfileView />
            </AppProviders>
        </App>,
    );
}

describe('ProfileView', () => {
    it.each(['employee', 'manager'] as const)('hides the edit form for a %s', async (role) => {
        renderProfile(role);
        expect(await screen.findByText('E-100')).toBeTruthy();
        expect(screen.getByText('Engineer')).toBeTruthy();
        expect(screen.getByText('Full time')).toBeTruthy();
        expect(screen.getByText('Engineering')).toBeTruthy();
        expect(screen.getByText('Head office')).toBeTruthy();
        expect(screen.getByText('Bob Manager')).toBeTruthy();
        expect(screen.getByText('03 Jun 2024')).toBeTruthy();
        expect(screen.getByText(role === 'manager' ? 'Alice Employee' : 'Employee')).toBeTruthy();
        expect(screen.getByText('Active')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Save profile' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Employee code' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Email' })).toBeNull();
    });

    it('shows the HR edit form and maps a problem+json field error', async () => {
        failFields = true;
        renderProfile('hr_admin');
        const code = await screen.findByRole('textbox', { name: 'Employee code' });
        expect(screen.getByRole('textbox', { name: 'Designation' })).toBeTruthy();
        expect(screen.getByRole('textbox', { name: 'Employment type' })).toBeTruthy();
        expect(screen.getByRole('combobox', { name: 'Department' })).toBeTruthy();
        expect(screen.getByRole('combobox', { name: 'Location' })).toBeTruthy();
        expect(screen.getByRole('combobox', { name: 'Manager' })).toBeTruthy();
        expect(screen.getByRole('textbox', { name: 'Date of joining' })).toBeTruthy();
        expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Email' })).toBeNull();
        fireEvent.change(code, { target: { value: 'E-200' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save profile' }));
        expect(await screen.findByText('Employee code is already used')).toBeTruthy();
        const patch = calls.find((call) => call.method === 'PATCH');
        expect(patch?.body).toMatchObject({
            employee_code: 'E-200',
            designation: 'Engineer',
            employment_type: 'Full time',
            department_id: DEPT_ID,
            location_id: LOCATION_ID,
            manager_id: MANAGER_ID,
            date_of_joining: '2024-06-03',
        });
        expect(patch?.body).not.toHaveProperty('role');
        expect(patch?.body).not.toHaveProperty('name');
        expect(patch?.body).not.toHaveProperty('email');
    });

    it('saves the profile for an HR admin', async () => {
        renderProfile('hr_admin');
        await screen.findByRole('button', { name: 'Save profile' });
        fireEvent.click(screen.getByRole('button', { name: 'Save profile' }));
        await waitFor(() => {
            expect(calls.some((call) => call.method === 'PATCH')).toBe(true);
        });
        expect(await screen.findByText('Profile saved')).toBeTruthy();
    });
});
