import { beforeEach, describe, expect, it, vi } from 'vitest';

const signInWithPassword = vi.hoisted(() => vi.fn());

vi.mock('@/auth', () => ({
    getAuth: () => ({ api: { signInWithPassword } }),
}));

import { POST } from './route';

const APP = 'http://localhost:8080';

function login(body: unknown, origin: string | null = APP) {
    const headers = new Headers({ 'Content-Type': 'application/json' });
    if (origin) headers.set('origin', origin);
    return new Request(`${APP}/bff/login`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
    });
}

function rejected() {
    const error = new Error('invalid_credentials');
    error.name = 'APIError';
    return Object.assign(error, { statusCode: 401 });
}

describe('POST /bff/login', () => {
    beforeEach(() => {
        signInWithPassword.mockReset();
        process.env.APP_URL = APP;
    });

    it('returns 401 without a cookie or the password when Keycloak rejects the password', async () => {
        signInWithPassword.mockRejectedValue(rejected());
        const res = await POST(login({ username: 'alice', password: 'pw-sentinel' }));
        expect(res.status).toBe(401);
        expect(res.headers.get('set-cookie')).toBeNull();
        expect(await res.json()).toEqual({ ok: false });
        expect(JSON.stringify(signInWithPassword.mock.calls)).toContain('pw-sentinel');
        expect(res.headers.get('cache-control')).toBe('no-store');
    });

    it('sets only the session cookie when sign-in succeeds', async () => {
        const headers = new Headers();
        headers.append('set-cookie', 'lms.session_token=signed; HttpOnly; Path=/');
        signInWithPassword.mockResolvedValue({
            headers,
            response: { ok: true, accessToken: 'no' },
        });
        const res = await POST(login({ username: 'alice', password: 'pw-sentinel' }));
        expect(res.status).toBe(200);
        expect(res.headers.get('set-cookie')).toBe('lms.session_token=signed; HttpOnly; Path=/');
        expect(await res.json()).toEqual({ ok: true });
    });

    it('refuses a cross-origin post before calling Keycloak', async () => {
        const res = await POST(
            login({ username: 'alice', password: 'pw-sentinel' }, 'https://evil.test'),
        );
        expect(res.status).toBe(403);
        expect(signInWithPassword).not.toHaveBeenCalled();
    });
});
