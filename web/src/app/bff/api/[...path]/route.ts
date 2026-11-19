import { clearSession, getAccessToken } from '@/auth';
import { proxy } from '@/bff/proxy';

export const dynamic = 'force-dynamic';

async function handler(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
    return proxy(req, (await ctx.params).path, {
        apiUrl: process.env.LMS_API_URL ?? '',
        appUrl: process.env.APP_URL ?? '',
        getToken: getAccessToken,
        clearSession,
    });
}

export { handler as GET, handler as POST, handler as PUT, handler as PATCH, handler as DELETE };
