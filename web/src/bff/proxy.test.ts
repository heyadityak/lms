import { describe, expect, it, vi } from 'vitest';
import { proxy, type ProxyDeps } from './proxy';

const APP = 'http://localhost:8080';

function deps(
    upstream?: Response,
    token: string | null = 'tok',
): ProxyDeps & { fetch: ReturnType<typeof vi.fn> } {
    return {
        apiUrl: 'http://nginx',
        appUrl: APP,
        getToken: vi.fn(async () => token),
        clearSession: vi.fn(async () => ['lms.session_token=; Max-Age=0; Path=/']),
        fetch: vi.fn(
            async () =>
                upstream ?? new Response('{}', { headers: { 'content-type': 'application/json' } }),
        ),
    };
}

const req = (path: string, init: RequestInit = {}) => new Request(`${APP}/bff/api/${path}`, init);

describe('BFF proxy', () => {
    it('forwards allow-listed request headers with the bearer token, never cookies', async () => {
        const d = deps();
        await proxy(
            req('leave-requests?page[number]=2', {
                method: 'POST',
                body: '{"a":1}',
                headers: {
                    origin: APP,
                    accept: 'application/json',
                    'content-type': 'application/json',
                    'idempotency-key': 'key-12345678',
                    cookie: 'lms.session_token=secret',
                    connection: 'keep-alive',
                    'x-forwarded-for': '203.0.113.9',
                },
            }),
            ['leave-requests'],
            d,
        );
        const [url, init] = d.fetch.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('http://nginx/api/v1/leave-requests?page[number]=2');
        const sent = new Headers(init.headers);
        expect(sent.get('authorization')).toBe('Bearer tok');
        expect(sent.get('idempotency-key')).toBe('key-12345678');
        expect(sent.get('content-type')).toBe('application/json');
        expect(sent.get('x-forwarded-for')).toBe('203.0.113.9');
        expect(sent.get('x-forwarded-host')).toBe('localhost:8080');
        expect(sent.get('cookie')).toBeNull();
        expect(sent.get('connection')).toBeNull();
        expect(await new Response(init.body).text()).toBe('{"a":1}');
    });

    it('passes problem+json, rate-limit headers and status through, strips hop-by-hop', async () => {
        const body = JSON.stringify({
            type: 'urn:lms:problem:leave-overlap',
            title: 'x',
            status: 422,
            code: 'LEAVE_OVERLAP',
        });
        const d = deps(
            new Response(body, {
                status: 422,
                headers: {
                    'content-type': 'application/problem+json',
                    'x-ratelimit-limit': '60',
                    'x-ratelimit-remaining': '59',
                    'retry-after': '1',
                    'x-request-id': 'req-1',
                    'idempotent-replayed': 'true',
                    'transfer-encoding': 'chunked',
                    'set-cookie': 'laravel=1',
                },
            }),
        );
        const res = await proxy(req('me'), ['me'], d);
        expect(res.status).toBe(422);
        expect(await res.text()).toBe(body);
        expect(res.headers.get('content-type')).toBe('application/problem+json');
        expect(res.headers.get('x-ratelimit-limit')).toBe('60');
        expect(res.headers.get('x-ratelimit-remaining')).toBe('59');
        expect(res.headers.get('retry-after')).toBe('1');
        expect(res.headers.get('x-request-id')).toBe('req-1');
        expect(res.headers.get('idempotent-replayed')).toBe('true');
        expect(res.headers.get('transfer-encoding')).toBeNull();
        expect(res.headers.get('set-cookie')).toBeNull();
    });

    it('streams CSV with its Content-Disposition', async () => {
        const stream = new ReadableStream({
            start(c) {
                c.enqueue(new TextEncoder().encode('a,b\n'));
                c.enqueue(new TextEncoder().encode('1,2\n'));
                c.close();
            },
        });
        const d = deps(
            new Response(stream, {
                headers: {
                    'content-type': 'text/csv',
                    'content-disposition': 'attachment; filename="r.csv"',
                },
            }),
        );
        const res = await proxy(
            req('reports/balances', { headers: { accept: 'text/csv' } }),
            ['reports', 'balances'],
            d,
        );
        expect(res.headers.get('content-disposition')).toBe('attachment; filename="r.csv"');
        expect(await res.text()).toBe('a,b\n1,2\n');
    });

    it('refuses mutating requests from another origin', async () => {
        const d = deps();
        for (const origin of ['https://evil.test', null]) {
            const headers: Record<string, string> = origin ? { origin } : {};
            const res = await proxy(
                req('leave-requests', { method: 'POST', headers }),
                ['leave-requests'],
                d,
            );
            expect(res.status).toBe(403);
            expect(res.headers.get('content-type')).toBe('application/problem+json');
            expect((await res.json()).code).toBe('FORBIDDEN');
        }
        expect(d.fetch).not.toHaveBeenCalled();
    });

    it('answers 401 problem+json without a session and clears the cookie', async () => {
        const d = deps(undefined, null);
        const res = await proxy(req('me'), ['me'], d);
        expect(res.status).toBe(401);
        expect(res.headers.get('content-type')).toBe('application/problem+json');
        expect(await res.json()).toMatchObject({ status: 401, code: 'UNAUTHENTICATED' });
        expect(res.headers.get('set-cookie')).toContain('Max-Age=0');
        expect(d.fetch).not.toHaveBeenCalled();
    });

    it('clears the session when Laravel answers 401', async () => {
        const d = deps(
            new Response('{}', {
                status: 401,
                headers: { 'www-authenticate': 'Bearer error="invalid_token"' },
            }),
        );
        const res = await proxy(req('me'), ['me'], d);
        expect(res.status).toBe(401);
        expect(res.headers.get('www-authenticate')).toBe('Bearer error="invalid_token"');
        expect(d.clearSession).toHaveBeenCalled();
        expect(res.headers.get('set-cookie')).toContain('Max-Age=0');
    });

    it('refuses path traversal out of /api/v1', async () => {
        const d = deps();
        const res = await proxy(req('x'), ['..', 'docs'], d);
        expect(res.status).toBe(404);
        expect(d.fetch).not.toHaveBeenCalled();
    });
});
