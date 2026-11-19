import { getAuth } from '@/auth';
import { originAllowed, problem } from '@/bff/problem';

export const dynamic = 'force-dynamic';

/** RP-initiated logout (D-45 (g)): delete the session, 303 to Keycloak end-session with id_token_hint. */
export async function POST(req: Request) {
    const appUrl = process.env.APP_URL ?? '';
    if (!originAllowed(req, appUrl))
        return problem(403, 'FORBIDDEN', 'Cross-origin request refused.');

    const home = new URL('/', appUrl).toString();
    let location = home;
    const res = new Response(null, { status: 303 });
    try {
        const { headers, response } = await getAuth().api.signOut({
            headers: req.headers,
            body: { callbackURL: home, disableRedirect: true },
            returnHeaders: true,
        });
        location = response.url ?? home;
        for (const cookie of headers.getSetCookie()) res.headers.append('set-cookie', cookie);
    } catch {
        // No session: still go home.
    }
    res.headers.set('location', location);
    return res;
}
