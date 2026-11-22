import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { getAccessToken } from '@/auth';
import { serverApi } from '@/lib/api/client';

/**
 * Current user, or null when the cookie is not a usable session.
 * `getAccessToken` checks the database session (the cookie cache does not), so a cookie the
 * proxy accepts but the API cannot use stays null here. The sign-in page uses the same
 * result: redirecting home on the cookie alone bounces `/signin` → `/` → the protected page.
 * React `cache` shares one lookup per request. The first call after sign-in provisions the user.
 */
export const loadMe = cache(async () => {
    const token = await getAccessToken(await headers());
    if (!token) return null;
    const { data } = await serverApi(token).GET('/me');
    return data?.data ?? null;
});

/** Signed-in user for the app shell. An unusable session goes to the welcome page. */
export async function getMe() {
    const me = await loadMe();
    if (!me) redirect('/signin');
    return me;
}
