import { originAllowed, problem } from './problem';

export interface ProxyDeps {
    apiUrl: string;
    appUrl: string;
    getToken(headers: Headers): Promise<string | null>;
    /** Deletes the session, returns Set-Cookie lines that clear its cookies. */
    clearSession(headers: Headers): Promise<string[]>;
    fetch?: typeof fetch;
}

const REQUEST_HEADERS = ['accept', 'content-type', 'idempotency-key'];
const RESPONSE_HEADERS = [
    'content-type',
    'content-disposition',
    'location',
    'retry-after',
    'idempotent-replayed',
    'x-request-id',
    'www-authenticate',
];

function withClearedCookies(res: Response, cookies: string[]): Response {
    for (const cookie of cookies) res.headers.append('set-cookie', cookie);
    return res;
}

/**
 * /bff/api/{path} → {LMS_API_URL}/api/v1/{path} with the session's bearer token.
 * Header allow-lists both ways (so hop-by-hop and Cookie never pass), bodies streamed.
 */
export async function proxy(req: Request, path: string[], deps: ProxyDeps): Promise<Response> {
    if (!originAllowed(req, deps.appUrl)) {
        return problem(403, 'FORBIDDEN', 'Cross-origin request refused.');
    }
    // Decoded segments: refuse anything that could climb out of /api/v1.
    if (path.length === 0 || path.some((s) => s === '' || s === '.' || s === '..')) {
        return problem(404, 'NOT_FOUND', 'The requested resource was not found.');
    }

    const token = await deps.getToken(req.headers);
    if (!token) {
        return withClearedCookies(
            problem(401, 'UNAUTHENTICATED', 'Sign in to continue.'),
            await deps.clearSession(req.headers),
        );
    }

    const incoming = new URL(req.url);
    const target = `${deps.apiUrl}/api/v1/${path.map(encodeURIComponent).join('/')}${incoming.search}`;
    const headers = new Headers({ authorization: `Bearer ${token}` });
    for (const name of REQUEST_HEADERS) {
        const value = req.headers.get(name);
        if (value) headers.set(name, value);
    }
    const forwardedFor = req.headers.get('x-forwarded-for');
    if (forwardedFor) headers.set('x-forwarded-for', forwardedFor);
    headers.set(
        'x-forwarded-proto',
        req.headers.get('x-forwarded-proto') ?? incoming.protocol.replace(':', ''),
    );
    headers.set('x-forwarded-host', req.headers.get('x-forwarded-host') ?? incoming.host);

    const hasBody = req.method !== 'GET' && req.method !== 'HEAD';
    let upstream: Response;
    try {
        upstream = await (deps.fetch ?? fetch)(target, {
            method: req.method,
            headers,
            body: hasBody ? req.body : undefined,
            redirect: 'manual',
            cache: 'no-store',
            ...(hasBody ? { duplex: 'half' } : {}),
        } as RequestInit);
    } catch {
        return problem(503, 'SERVICE_UNAVAILABLE', 'The API is unreachable.');
    }

    const out = new Headers();
    upstream.headers.forEach((value, name) => {
        if (RESPONSE_HEADERS.includes(name) || name.startsWith('x-ratelimit-'))
            out.set(name, value);
    });
    const res = new Response(upstream.body, { status: upstream.status, headers: out });
    // Laravel 401 (deactivated user, revoked token): drop the BFF session too (D-43 (e)).
    return upstream.status === 401
        ? withClearedCookies(res, await deps.clearSession(req.headers))
        : res;
}
