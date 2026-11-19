import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { cache } from 'react';
import { getAccessToken } from '@/auth';
import { serverApi } from '@/lib/api/client';

/**
 * Current user for this server render; React `cache` shares one `GET /me` between the (app)
 * layout and the page. The first call after sign-in provisions the user (D-43 (f)).
 */
export const getMe = cache(async () => {
    const token = await getAccessToken(await headers());
    if (!token) redirect('/signin');
    const { data } = await serverApi(token).GET('/me');
    if (!data) redirect('/signin');
    return data.data;
});
