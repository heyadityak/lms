import type { Schemas } from '@/lib/api/client';

type Code = Schemas['ProblemCode'];

const TITLES: Partial<Record<Code, string>> = {
    UNAUTHENTICATED: 'Unauthenticated',
    FORBIDDEN: 'Forbidden',
    NOT_FOUND: 'Not Found',
    SERVICE_UNAVAILABLE: 'Service Unavailable',
};

/** RFC 9457 problem in the same shape Laravel renders (API_GUIDELINES §4). */
export function problem(status: number, code: Code, detail?: string): Response {
    const body: Schemas['Problem'] = {
        type: `urn:lms:problem:${code.toLowerCase().replaceAll('_', '-')}`,
        title: TITLES[code] ?? code,
        status,
        code,
        ...(detail ? { detail } : {}),
    };
    return Response.json(body, {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

/** CSRF check for /bff/*: mutating methods need Origin = APP_URL origin (D-43 (e)). */
export function originAllowed(req: Request, appUrl: string): boolean {
    return !MUTATING.has(req.method) || req.headers.get('origin') === new URL(appUrl).origin;
}
