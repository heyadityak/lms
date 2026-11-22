import { describe, expect, it, vi } from 'vitest';
import {
    credentialsFrom,
    grantFromPassword,
    PasswordGrantError,
    type KeycloakPasswordConfig,
} from './password-grant';

const config: KeycloakPasswordConfig = {
    baseUrl: 'http://keycloak:8080',
    realm: 'lms',
    clientId: 'lms-web',
    clientSecret: 'client-secret',
};

const PASSWORD = 'pw-sentinel';

function jwt(payload: Record<string, unknown>): string {
    const body = Buffer.from(JSON.stringify(payload)).toString('base64url');
    return `eyJhbGciOiJub25lIn0.${body}.sig`;
}

function json(status: number, body: unknown) {
    return new Response(typeof body === 'string' ? body : JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('credentialsFrom', () => {
    it('returns only the username and password', () => {
        expect(credentialsFrom({ username: ' alice ', password: PASSWORD, extra: 'no' })).toEqual({
            username: 'alice',
            password: PASSWORD,
        });
        expect(credentialsFrom({ username: ' ', password: 'x' })).toBeNull();
        expect(credentialsFrom({ username: 'a', password: '' })).toBeNull();
        expect(credentialsFrom(null)).toBeNull();
    });
});

describe('grantFromPassword', () => {
    it('rejects a 401 without copying the password into the error', async () => {
        const fetchImpl = vi.fn<(url: RequestInfo | URL, init?: RequestInit) => Promise<Response>>(
            async () => json(401, PASSWORD),
        );
        const error = await grantFromPassword(
            { username: 'alice', password: PASSWORD },
            config,
            fetchImpl,
        ).catch((caught: unknown) => caught);
        expect(error).toBeInstanceOf(PasswordGrantError);
        expect(error).toMatchObject({ kind: 'rejected', message: 'rejected' });
        expect(JSON.stringify(error)).not.toContain(PASSWORD);

        const [url, init] = fetchImpl.mock.calls[0] ?? [];
        expect(String(url)).toBe('http://keycloak:8080/realms/lms/protocol/openid-connect/token');
        expect(String(url)).not.toContain(config.clientSecret);
        const sent = new URLSearchParams(String(init?.body));
        expect(sent.get('grant_type')).toBe('password');
        expect(sent.get('username')).toBe('alice');
        expect(sent.get('password')).toBe(PASSWORD);
        expect(sent.get('client_id')).toBe('lms-web');
        expect(sent.get('client_secret')).toBe('client-secret');
    });

    it('returns identity and tokens, never the password', async () => {
        const fetchImpl = vi.fn(async () =>
            json(200, {
                access_token: 'access-1',
                refresh_token: 'refresh-1',
                id_token: jwt({
                    sub: 'kc-sub',
                    email: 'alice@example.com',
                    email_verified: true,
                    name: 'Alice',
                }),
                expires_in: 300,
                refresh_expires_in: 1800,
                scope: 'openid profile email',
                password: PASSWORD,
            }),
        );
        const identity = await grantFromPassword(
            { username: 'alice', password: PASSWORD },
            config,
            fetchImpl,
        );
        expect(identity.sub).toBe('kc-sub');
        expect(identity.email).toBe('alice@example.com');
        expect(identity.emailVerified).toBe(true);
        expect(identity.name).toBe('Alice');
        expect(identity.accessToken).toBe('access-1');
        expect(identity.refreshToken).toBe('refresh-1');
        expect(identity).not.toHaveProperty('password');
        expect(JSON.stringify(identity)).not.toContain(PASSWORD);
        expect(fetchImpl).toHaveBeenCalledOnce();
    });

    it('fills a missing email from the userinfo endpoint', async () => {
        const fetchImpl = vi.fn<(url: RequestInfo | URL, init?: RequestInit) => Promise<Response>>(
            async (url) => {
                if (String(url).endsWith('/userinfo')) {
                    return json(200, {
                        sub: 'kc-sub',
                        email: 'alice@example.com',
                        email_verified: true,
                    });
                }
                return json(200, {
                    access_token: 'access-1',
                    refresh_token: 'refresh-1',
                    id_token: jwt({ sub: 'kc-sub' }),
                    expires_in: 60,
                });
            },
        );
        const identity = await grantFromPassword(
            { username: 'alice', password: PASSWORD },
            config,
            fetchImpl,
        );
        expect(identity.email).toBe('alice@example.com');
        expect(identity.name).toBe('alice');
        const [, userinfoInit] = fetchImpl.mock.calls[1] ?? [];
        expect(String(fetchImpl.mock.calls[1]?.[0])).toBe(
            'http://keycloak:8080/realms/lms/protocol/openid-connect/userinfo',
        );
        expect(new Headers(userinfoInit?.headers).get('authorization')).toBe('Bearer access-1');
        expect(JSON.stringify(userinfoInit)).not.toContain(PASSWORD);
    });

    it('treats a non-401 failure as unavailable', async () => {
        const fetchImpl = vi.fn(async () => json(400, { error: 'invalid_grant' }));
        await expect(
            grantFromPassword({ username: 'alice', password: PASSWORD }, config, fetchImpl),
        ).rejects.toMatchObject({ kind: 'unavailable' });
    });
});
