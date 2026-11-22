// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DepartmentsView } from './DepartmentsView';

const DEPT = {
    id: '01J0000000000000000000000D',
    code: 'ENG',
    name: 'Engineering',
    users_count: 2,
};

interface Call {
    url: string;
    method: string;
    body: unknown;
}

const calls: Call[] = [];
let failDelete = false;

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
    failDelete = false;
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        let body: unknown;
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            const text = await req.text();
            body = text ? JSON.parse(text) : undefined;
        }
        calls.push({ url: req.url, method: req.method, body });
        const path = new URL(req.url).pathname;
        if (req.method === 'GET' && path.endsWith('/departments')) {
            return json(200, { data: failDelete ? [DEPT] : [], meta: {}, links: {} });
        }
        if (req.method === 'POST' && path.endsWith('/departments')) {
            return json(422, {
                type: 'urn:lms:problem:validation-failed',
                title: 'The request is invalid',
                status: 422,
                code: 'VALIDATION_FAILED',
                detail: 'Check the form',
                errors: { name: ['Name is already used'] },
            });
        }
        if (req.method === 'DELETE') {
            return json(409, {
                type: 'urn:lms:problem:department-not-empty',
                title: 'Department not empty',
                status: 409,
                code: 'DEPARTMENT_NOT_EMPTY',
                detail: 'Move or deactivate the 2 users in this department first.',
            });
        }
        return json(500, { title: 'unexpected', status: 500, code: 'INTERNAL_ERROR' });
    });
});

function renderView() {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <App>
                <DepartmentsView />
            </App>
        </QueryClientProvider>,
    );
}

describe('DepartmentsView', () => {
    it('maps a problem+json field error onto the name field', async () => {
        renderView();
        fireEvent.click(await screen.findByRole('button', { name: 'Add department' }));
        fireEvent.change(await screen.findByRole('textbox', { name: 'Code' }), {
            target: { value: 'IT' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Name' }), {
            target: { value: 'Information Technology' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('Name is already used')).toBeTruthy();
        const post = calls.find((call) => call.method === 'POST');
        expect(post?.body).toEqual({ code: 'IT', name: 'Information Technology' });
    });

    it('shows the API error when a department is not empty', async () => {
        failDelete = true;
        renderView();
        fireEvent.click(await screen.findByRole('button', { name: 'Delete Engineering' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Delete department' }));
        expect(
            await screen.findByText('Move or deactivate the 2 users in this department first.'),
        ).toBeTruthy();
        await waitFor(() => expect(calls.some((call) => call.method === 'DELETE')).toBe(true));
    });
});
