import { getSessionCookie } from 'better-auth/cookies';
import { NextResponse, type NextRequest } from 'next/server';

/**
 * Optimistic gate (cookie presence only); the (app) layout validates the session.
 * /bff answers its own 401 problem+json, /auth belongs to Better Auth.
 */
export function proxy(req: NextRequest) {
    if (getSessionCookie(req, { cookiePrefix: 'lms' })) return NextResponse.next();
    return NextResponse.redirect(new URL('/signin', process.env.APP_URL ?? req.url));
}

export const config = {
    matcher: ['/((?!signin|auth/|bff/|_next/|favicon.ico).*)'],
};
