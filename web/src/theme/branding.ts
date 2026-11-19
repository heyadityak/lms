import { cache } from 'react';
import { DEFAULT_BRANDING, type Branding } from './brand';

/** Public branding, server-side, short Next fetch cache (D-43 (i)). Never throws. */
export const getBranding = cache(async (): Promise<Branding> => {
    try {
        const res = await fetch(`${process.env.LMS_API_URL}/api/v1/branding`, {
            headers: { Accept: 'application/json' },
            next: {
                revalidate: Number(process.env.BRANDING_REVALIDATE_SECONDS ?? 30),
                tags: ['branding'],
            },
        });
        if (!res.ok) return DEFAULT_BRANDING;
        const body = (await res.json()) as { data?: Partial<Branding> };
        return { ...DEFAULT_BRANDING, ...body.data };
    } catch {
        return DEFAULT_BRANDING;
    }
});
