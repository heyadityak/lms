import { isAPIError } from 'better-auth/api';
import { getAuth } from '@/auth';
import { credentialsFrom } from '@/auth/password-grant';
import { originAllowed, problem } from '@/bff/problem';

export const dynamic = 'force-dynamic';

const NO_STORE = { 'Cache-Control': 'no-store' };

function noStore(response: Response): Response {
    response.headers.set('Cache-Control', 'no-store');
    return response;
}

/** Same-origin username/password sign-in. The browser receives only the session cookie. */
export async function POST(req: Request) {
    const appUrl = process.env.APP_URL ?? '';
    if (!originAllowed(req, appUrl)) {
        return noStore(problem(403, 'FORBIDDEN', 'Cross-origin request refused.'));
    }

    let credentials: { username: string; password: string } | null;
    try {
        credentials = credentialsFrom(await req.json());
    } catch {
        credentials = null;
    }
    if (!credentials) {
        return noStore(problem(400, 'VALIDATION_FAILED', 'Username and password are required.'));
    }

    try {
        const { headers } = await getAuth().api.signInWithPassword({
            body: credentials,
            headers: req.headers,
            returnHeaders: true,
        });
        const cookies = headers.getSetCookie();
        if (cookies.length === 0) {
            return noStore(
                problem(503, 'SERVICE_UNAVAILABLE', 'Sign-in is unavailable. Try again shortly.'),
            );
        }
        const res = Response.json({ ok: true }, { headers: NO_STORE });
        for (const cookie of cookies) res.headers.append('set-cookie', cookie);
        return res;
    } catch (error) {
        if (isAPIError(error) && error.statusCode === 401) {
            return Response.json({ ok: false }, { status: 401, headers: NO_STORE });
        }
        return noStore(
            problem(503, 'SERVICE_UNAVAILABLE', 'Sign-in is unavailable. Try again shortly.'),
        );
    }
}
