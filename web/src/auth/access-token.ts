/** Refresh when less than this is left on the access token (D-45 (f)). */
export const REFRESH_SKEW_MS = 60_000;
/** Below this, Better Auth's getAccessToken refreshes by itself; treat as unusable. */
const MIN_USABLE_MS = 5_000;

export interface TokenSet {
    accessToken?: string;
    accessTokenExpiresAt?: Date | string;
}

/** The slice of Better Auth's server API the helper needs (injected for tests). */
export interface TokenApi {
    /** Throws when there is no session. */
    listAccounts(headers: Headers): Promise<{ id: string; providerId: string }[]>;
    getAccessToken(headers: Headers, accountId: string): Promise<TokenSet>;
    refreshToken(headers: Headers, accountId: string): Promise<TokenSet>;
    /** Deletes the session row (the caller clears cookies if it can). */
    deleteSession(headers: Headers): Promise<void>;
}

function msLeft(tokens: TokenSet, now: number): number {
    if (!tokens.accessTokenExpiresAt) return Infinity;
    return new Date(tokens.accessTokenExpiresAt).getTime() - now;
}

/**
 * Keycloak access token for the session in `headers`, refreshed server-side when it has
 * under 60 s left. Returns null when there is no session or the token cannot be renewed
 * (the session is then deleted, so the user signs in again).
 */
export async function accessTokenFor(
    api: TokenApi,
    headers: Headers,
    now: () => number = Date.now,
): Promise<string | null> {
    let accountId: string;
    try {
        const account = (await api.listAccounts(headers)).find((a) => a.providerId === 'keycloak');
        if (!account) return null;
        accountId = account.id;
    } catch {
        return null;
    }

    try {
        const tokens = await api.getAccessToken(headers, accountId);
        if (tokens.accessToken && msLeft(tokens, now()) >= REFRESH_SKEW_MS)
            return tokens.accessToken;
        const refreshed = await api.refreshToken(headers, accountId);
        if (refreshed.accessToken) return refreshed.accessToken;
    } catch {
        // Another replica may have just rotated the refresh token (Keycloak max reuse 1): re-read once.
        try {
            const tokens = await api.getAccessToken(headers, accountId);
            if (tokens.accessToken && msLeft(tokens, now()) > MIN_USABLE_MS)
                return tokens.accessToken;
        } catch {
            // fall through
        }
    }
    await api.deleteSession(headers).catch(() => {});
    return null;
}
