import { APIError, createAuthEndpoint, isAPIError } from 'better-auth/api';
import { setSessionCookie } from 'better-auth/cookies';
import { handleOAuthUserInfo } from 'better-auth/oauth2';

const REQUESTED_SCOPE = 'openid profile email';
const TOKEN_PATH = '/protocol/openid-connect/token';
const USERINFO_PATH = '/protocol/openid-connect/userinfo';

/** Keycloak refused the username or password. The message never includes either. */
export class PasswordGrantError extends Error {
    readonly kind: 'rejected' | 'unavailable';

    constructor(kind: 'rejected' | 'unavailable') {
        super(kind);
        this.name = 'PasswordGrantError';
        this.kind = kind;
    }
}

export interface KeycloakPasswordConfig {
    baseUrl: string;
    realm: string;
    clientId: string;
    clientSecret: string;
}

/** Identity and tokens for a Better Auth account row. No password field. */
export interface GrantedIdentity {
    sub: string;
    email: string;
    emailVerified: boolean;
    name: string;
    accessToken: string;
    refreshToken: string;
    idToken?: string;
    accessTokenExpiresAt: Date;
    refreshTokenExpiresAt?: Date;
    scope: string;
}

export function credentialsFrom(value: unknown): { username: string; password: string } | null {
    if (!value || typeof value !== 'object') return null;
    const record = value as Record<string, unknown>;
    if (typeof record.username !== 'string' || typeof record.password !== 'string') return null;
    const username = record.username.trim();
    const password = record.password;
    if (!username || !password || username.length > 255 || password.length > 1024) return null;
    return { username, password };
}

export function keycloakPasswordConfig(): KeycloakPasswordConfig {
    const baseUrl = process.env.KEYCLOAK_BASE_URL?.replace(/\/$/, '');
    const clientId = process.env.KEYCLOAK_CLIENT_ID;
    const clientSecret = process.env.KEYCLOAK_CLIENT_SECRET;
    const realm = process.env.KEYCLOAK_REALM || 'lms';
    if (!baseUrl || !clientId || !clientSecret) throw new PasswordGrantError('unavailable');
    return { baseUrl, realm, clientId, clientSecret };
}

function claimString(claims: Record<string, unknown>, key: string): string | null {
    const value = claims[key];
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    return trimmed || null;
}

function jwtClaims(token: string): Record<string, unknown> | null {
    const segment = token.split('.')[1];
    if (!segment) return null;
    try {
        const json = Buffer.from(segment, 'base64url').toString('utf8');
        const value: unknown = JSON.parse(json);
        if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
        return value as Record<string, unknown>;
    } catch {
        return null;
    }
}

function asClaims(value: unknown): Record<string, unknown> | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    return value as Record<string, unknown>;
}

function profileFrom(
    claims: Record<string, unknown>,
    username: string,
): Pick<GrantedIdentity, 'sub' | 'email' | 'emailVerified' | 'name'> | null {
    const sub = claimString(claims, 'sub');
    const email = claimString(claims, 'email');
    if (!sub || !email) return null;
    return {
        sub,
        email,
        emailVerified: claims.email_verified === true,
        name: claimString(claims, 'name') ?? claimString(claims, 'preferred_username') ?? username,
    };
}

function expiresAt(seconds: number | undefined, fallbackSeconds: number): Date {
    const value =
        seconds !== undefined && Number.isFinite(seconds) && seconds > 0
            ? seconds
            : fallbackSeconds;
    return new Date(Date.now() + value * 1000);
}

interface TokenPayload {
    accessToken: string;
    refreshToken: string;
    idToken?: string;
    expiresIn?: number;
    refreshExpiresIn?: number;
    scope?: string;
}

function tokenPayload(value: unknown): TokenPayload | null {
    const record = asClaims(value);
    if (!record) return null;
    if (typeof record.access_token !== 'string' || !record.access_token) return null;
    if (typeof record.refresh_token !== 'string' || !record.refresh_token) return null;
    return {
        accessToken: record.access_token,
        refreshToken: record.refresh_token,
        idToken: typeof record.id_token === 'string' ? record.id_token : undefined,
        expiresIn: typeof record.expires_in === 'number' ? record.expires_in : undefined,
        refreshExpiresIn:
            typeof record.refresh_expires_in === 'number' ? record.refresh_expires_in : undefined,
        scope: typeof record.scope === 'string' ? record.scope : undefined,
    };
}

async function postForm(
    url: string,
    body: URLSearchParams,
    fetchImpl: typeof fetch,
): Promise<Response> {
    try {
        return await fetchImpl(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body,
            cache: 'no-store',
            redirect: 'error',
            signal: AbortSignal.timeout(10_000),
        });
    } catch {
        throw new PasswordGrantError('unavailable');
    }
}

async function readUserInfo(
    url: string,
    accessToken: string,
    fetchImpl: typeof fetch,
): Promise<Record<string, unknown> | null> {
    let response: Response;
    try {
        response = await fetchImpl(url, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
            cache: 'no-store',
            redirect: 'error',
            signal: AbortSignal.timeout(10_000),
        });
    } catch {
        return null;
    }
    if (!response.ok) {
        await response.arrayBuffer().catch(() => undefined);
        return null;
    }
    try {
        return asClaims(JSON.parse(await response.text()) as unknown);
    } catch {
        return null;
    }
}

/**
 * Resource-owner password grant against Keycloak. Returns identity and tokens only.
 * The password is sent to the token endpoint and is not copied onto the result.
 */
export async function grantFromPassword(
    credentials: { username: string; password: string },
    config: KeycloakPasswordConfig,
    fetchImpl: typeof fetch = fetch,
): Promise<GrantedIdentity> {
    const realm = encodeURIComponent(config.realm);
    const tokenUrl = `${config.baseUrl}/realms/${realm}${TOKEN_PATH}`;
    const body = new URLSearchParams({
        grant_type: 'password',
        client_id: config.clientId,
        client_secret: config.clientSecret,
        username: credentials.username,
        password: credentials.password,
        scope: REQUESTED_SCOPE,
    });
    const response = await postForm(tokenUrl, body, fetchImpl);
    if (response.status === 401) {
        await response.arrayBuffer().catch(() => undefined);
        throw new PasswordGrantError('rejected');
    }
    if (!response.ok) {
        await response.arrayBuffer().catch(() => undefined);
        throw new PasswordGrantError('unavailable');
    }

    let parsed: unknown;
    try {
        parsed = JSON.parse(await response.text()) as unknown;
    } catch {
        throw new PasswordGrantError('unavailable');
    }
    const tokens = tokenPayload(parsed);
    if (!tokens) throw new PasswordGrantError('unavailable');

    let claims = tokens.idToken ? (jwtClaims(tokens.idToken) ?? {}) : {};
    if (!profileFrom(claims, credentials.username)) {
        const userInfo = await readUserInfo(
            `${config.baseUrl}/realms/${realm}${USERINFO_PATH}`,
            tokens.accessToken,
            fetchImpl,
        );
        if (userInfo) claims = { ...claims, ...userInfo };
    }
    const profile = profileFrom(claims, credentials.username);
    if (!profile) throw new PasswordGrantError('unavailable');

    return {
        ...profile,
        accessToken: tokens.accessToken,
        refreshToken: tokens.refreshToken,
        idToken: tokens.idToken,
        accessTokenExpiresAt: expiresAt(tokens.expiresIn, 300),
        refreshTokenExpiresAt:
            tokens.refreshExpiresIn !== undefined && tokens.refreshExpiresIn > 0
                ? expiresAt(tokens.refreshExpiresIn, tokens.refreshExpiresIn)
                : undefined,
        scope: tokens.scope ?? REQUESTED_SCOPE,
    };
}

type OAuthContext = Parameters<typeof handleOAuthUserInfo>[0];

/**
 * Same persistence as the Keycloak OAuth callback: user + encrypted account tokens
 * via handleOAuthUserInfo, then the signed HttpOnly session cookie.
 */
async function openKeycloakSession(ctx: OAuthContext, identity: GrantedIdentity): Promise<void> {
    const result = await handleOAuthUserInfo(ctx, {
        userInfo: {
            id: identity.sub,
            email: identity.email,
            name: identity.name,
            emailVerified: identity.emailVerified,
        },
        account: {
            providerId: 'keycloak',
            accountId: identity.sub,
            accessToken: identity.accessToken,
            refreshToken: identity.refreshToken,
            idToken: identity.idToken,
            accessTokenExpiresAt: identity.accessTokenExpiresAt,
            refreshTokenExpiresAt: identity.refreshTokenExpiresAt,
            scope: identity.scope,
        },
        callbackURL: '/',
        overrideUserInfo: true,
        isTrustedProvider: true,
    });
    if (result.error || !result.data) {
        ctx.context.logger.error('password grant could not open a session');
        throw new APIError('SERVICE_UNAVAILABLE', { message: 'idp_unavailable' });
    }
    await setSessionCookie(ctx, { session: result.data.session, user: result.data.user });
}

type PasswordBody = { username: string; password: string };

/** Standard Schema so the server API accepts `{ username, password }` and nothing else. */
const passwordBody = {
    '~standard': {
        version: 1 as const,
        vendor: 'lms',
        validate(value: unknown) {
            const credentials = credentialsFrom(value);
            if (!credentials) {
                return { issues: [{ message: 'Username and password are required' }] };
            }
            return { value: credentials };
        },
        types: {
            input: {} as PasswordBody,
            output: {} as PasswordBody,
        },
    },
};

/** Server-only Better Auth endpoint. Not mounted on `/auth` (no path, SERVER_ONLY). */
export function passwordGrantPlugin() {
    return {
        id: 'password-grant' as const,
        endpoints: {
            signInWithPassword: createAuthEndpoint.serverOnly(
                { method: 'POST', body: passwordBody },
                async (ctx) => {
                    const credentials = {
                        username: ctx.body.username,
                        password: ctx.body.password,
                    };
                    ctx.body.password = '';
                    try {
                        const identity = await grantFromPassword(
                            credentials,
                            keycloakPasswordConfig(),
                        );
                        credentials.password = '';
                        await openKeycloakSession(ctx as OAuthContext, identity);
                    } catch (error) {
                        if (error instanceof PasswordGrantError) {
                            if (error.kind === 'rejected') {
                                throw new APIError('UNAUTHORIZED', {
                                    message: 'invalid_credentials',
                                });
                            }
                            throw new APIError('SERVICE_UNAVAILABLE', {
                                message: 'idp_unavailable',
                            });
                        }
                        if (isAPIError(error)) throw error;
                        throw new APIError('SERVICE_UNAVAILABLE', { message: 'idp_unavailable' });
                    }
                    return ctx.json({ ok: true });
                },
            ),
        },
    };
}
