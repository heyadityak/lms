// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Schemas } from '@/lib/api/client';
import { ModulesView } from './ModulesView';

vi.mock('@/lib/revalidate-branding', () => ({
    revalidateBranding: vi.fn(async () => undefined),
}));

type Module = Schemas['Module'];

interface Call {
    method: string;
    body: unknown;
}

const calls: Call[] = [];
let modules: Module[] = [];
let patchStatus = 200;

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
    patchStatus = 200;
    modules = [
        {
            key: 'multi_level',
            label: 'Delegation',
            licensed: false,
            available: false,
            enabled: false,
            locked: true,
        },
    ];
    vi.stubGlobal('fetch', async (input: RequestInfo | URL, init?: RequestInit) => {
        const req = input instanceof Request ? input : new Request(input, init);
        let body: unknown;
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            const text = await req.text();
            body = text ? JSON.parse(text) : undefined;
        }
        calls.push({ method: req.method, body });
        if (req.method === 'PATCH') {
            if (patchStatus === 422) {
                return json(422, {
                    type: 'urn:lms:problem:module-not-licensed',
                    title: 'Module not licensed',
                    status: 422,
                    code: 'MODULE_NOT_LICENSED',
                    detail: 'Delegation is not licensed for this deployment.',
                });
            }
            return json(200, { data: modules[0], meta: {}, links: {} });
        }
        return json(200, { data: modules, meta: {}, links: {} });
    });
});

function renderView() {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <App>
                <ModulesView />
            </App>
        </QueryClientProvider>,
    );
}

describe('ModulesView', () => {
    it('does not PATCH when the module switch is locked', async () => {
        renderView();
        const sw = await screen.findByRole('switch', { name: 'Delegation' });
        expect((sw as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(sw);
        expect(calls.filter((call) => call.method === 'PATCH')).toHaveLength(0);
    });

    it('shows MODULE_NOT_LICENSED as a problem alert', async () => {
        modules = [
            {
                key: 'multi_level',
                label: 'Delegation',
                licensed: true,
                available: true,
                enabled: false,
                locked: false,
            },
        ];
        patchStatus = 422;
        renderView();
        fireEvent.click(await screen.findByRole('switch', { name: 'Delegation' }));
        expect(await screen.findByText('Module is not licensed')).toBeTruthy();
        await waitFor(() => expect(calls.some((call) => call.method === 'PATCH')).toBe(true));
        expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ enabled: true });
    });
});
