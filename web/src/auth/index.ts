import { betterAuth } from 'better-auth';
import { nextCookies } from 'better-auth/next-js';
import { accessTokenFor, type TokenApi } from './access-token';
import { authOptions } from './options.mjs';

function createAuth() {
    const options = authOptions();
    return betterAuth({ ...options, plugins: [...options.plugins, nextCookies()] });
}

let instance: ReturnType<typeof createAuth> | undefined;

/** Lazy, so `next build` never needs runtime env or a database (12-factor). */
export function getAuth() {
    instance ??= createAuth();
    return instance;
}

const tokenApi: TokenApi = {
    listAccounts: (headers) => getAuth().api.listUserAccounts({ headers }),
    getAccessToken: (headers, accountId) =>
        getAuth().api.getAccessToken({ headers, body: { accountId } }),
    refreshToken: (headers, accountId) =>
        getAuth().api.refreshToken({ headers, body: { accountId } }),
    deleteSession: async (headers) => {
        await getAuth().api.signOut({ headers });
    },
};

/** Access token for the request's session (BFF proxy, server components). */
export function getAccessToken(headers: Headers): Promise<string | null> {
    return accessTokenFor(tokenApi, headers);
}

/** Deletes the session (if any) and returns the Set-Cookie lines that clear the cookies. */
export async function clearSession(headers: Headers): Promise<string[]> {
    try {
        const result = await getAuth().api.signOut({ headers, returnHeaders: true });
        return result.headers.getSetCookie();
    } catch {
        return [];
    }
}

export function getSession(headers: Headers) {
    return getAuth().api.getSession({ headers });
}
