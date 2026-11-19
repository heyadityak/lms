// Container healthcheck: no I/O (D-43 (a)).
export const dynamic = 'force-dynamic';

export function GET() {
    return Response.json({ status: 'ok' });
}
