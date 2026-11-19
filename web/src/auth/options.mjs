// Better Auth options shared by the app (src/auth/index.ts) and the one-off migrate job
// (scripts/auth-migrate.mjs), so both see the same schema (D-45). Plain JS: the job runs with node.
import { genericOAuth } from 'better-auth/plugins/generic-oauth';
import pg from 'pg';

/** @param {string} name */
function required(name) {
    const value = process.env[name];
    if (!value) throw new Error(`Missing required env ${name}`);
    return value;
}

export function authOptions() {
    const appUrl = required('APP_URL');
    return /** @satisfies {import('better-auth').BetterAuthOptions} */ ({
        appName: 'LMS',
        baseURL: appUrl,
        basePath: '/auth',
        secret: required('BETTER_AUTH_SECRET'),
        trustedOrigins: [new URL(appUrl).origin],
        database: new pg.Pool({ connectionString: required('AUTH_DATABASE_URL') }),
        telemetry: { enabled: false },
        // Endpoints that would hand provider tokens to the browser, or edit the Keycloak mirror.
        // auth.api.* calls on the server are not affected (disabledPaths is router-only).
        disabledPaths: [
            '/get-access-token',
            '/refresh-token',
            '/account-info',
            '/link-social',
            '/unlink-account',
            '/update-user',
            '/delete-user',
            '/change-email',
        ],
        session: {
            expiresIn: Number(process.env.AUTH_SESSION_MAX_AGE ?? 36000),
            updateAge: 3600,
            cookieCache: { enabled: true, strategy: 'jwe', maxAge: 300 },
        },
        account: {
            encryptOAuthTokens: true,
            storeAccountCookie: false,
            accountLinking: { trustedProviders: ['keycloak'] },
        },
        rateLimit: { enabled: true, storage: 'database' },
        advanced: {
            cookiePrefix: 'lms',
            useSecureCookies: appUrl.startsWith('https://'),
        },
        plugins: [
            genericOAuth({
                config: [
                    {
                        providerId: 'keycloak',
                        discoveryUrl: `${required('KEYCLOAK_BASE_URL')}/realms/${process.env.KEYCLOAK_REALM ?? 'lms'}/.well-known/openid-configuration`,
                        clientId: required('KEYCLOAK_CLIENT_ID'),
                        clientSecret: required('KEYCLOAK_CLIENT_SECRET'),
                        scopes: ['openid', 'profile', 'email'],
                        pkce: true,
                        requireIdTokenVerification: true,
                        overrideUserInfo: true,
                        postLogoutRedirectURI: '/',
                    },
                ],
            }),
        ],
    });
}
