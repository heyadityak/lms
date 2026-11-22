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

/**
 * Browser-side client: same paths through the BFF proxy. Absolute base (Request needs one outside
 * a real browser) and fetch resolved per call, so tests can stub `globalThis.fetch`.
 */
export const browserApi = createClient<paths>({
    baseUrl: `${typeof window === 'undefined' ? '' : window.location.origin}/bff/api`,
    headers: { Accept: 'application/json' },
    fetch: (req) => globalThis.fetch(req),
});

export type Problem = Partial<Schemas['ValidationProblem']>;

/** Non-2xx API answer carrying the problem+json body (API_GUIDELINES §4). */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        readonly problem: Problem,
    ) {
        super(problem.detail ?? problem.title ?? `Request failed (${status})`);
    }
}

/** openapi-fetch result → data, or throw ApiError. */
export async function unwrap<T>(
    call: Promise<{ data?: T; error?: unknown; response: Response }>,
): Promise<T> {
    const { data, error, response } = await call;
    if (!response.ok || data === undefined) {
        throw new ApiError(response.status, (error ?? {}) as Problem);
    }
    return data;
}

/** Success with an empty body (204). `unwrap` treats a missing body as failure. */
export async function unwrapEmpty(
    call: Promise<{ data?: unknown; error?: unknown; response: Response }>,
): Promise<void> {
    const { error, response } = await call;
    if (!response.ok) throw new ApiError(response.status, (error ?? {}) as Problem);
}
