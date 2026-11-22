import { ApiError, type Problem } from '@/lib/api/client';

/**
 * `GET /bff/api/{path}` with `Accept: text/csv`. The BFF forwards that header to Laravel
 * and streams the body back (no pagination).
 */
export async function downloadCsv(
    path: string,
    query: Record<string, string | number | undefined>,
    fallbackName: string,
): Promise<void> {
    const url = new URL(`/bff/api/${path}`, window.location.origin);
    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== '') url.searchParams.set(key, String(value));
    }
    const res = await fetch(url, { headers: { Accept: 'text/csv' } });
    if (!res.ok) {
        let problem: Problem = { title: 'Download failed', status: res.status };
        try {
            problem = (await res.json()) as Problem;
        } catch {
            // keep the fallback problem
        }
        throw new ApiError(res.status, problem);
    }
    const blob = await res.blob();
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filenameFrom(res.headers.get('Content-Disposition'), fallbackName);
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

function filenameFrom(header: string | null, fallback: string): string {
    if (!header) return fallback;
    const quoted = /filename="([^"]+)"/i.exec(header);
    if (quoted?.[1]) return quoted[1];
    const plain = /filename=([^;]+)/i.exec(header);
    return plain?.[1]?.trim() || fallback;
}
