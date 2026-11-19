import createClient from 'openapi-fetch';
import type { components, paths } from './schema';

export type Schemas = components['schemas'];

/** Server-side client: Laravel through nginx with the session's bearer token (D-43 (e)). */
export function serverApi(token: string) {
    return createClient<paths>({
        baseUrl: `${process.env.LMS_API_URL}/api/v1`,
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        cache: 'no-store',
    });
}

/** Browser-side client: same paths through the BFF proxy. */
export const browserApi = createClient<paths>({ baseUrl: '/bff/api' });
