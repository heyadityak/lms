'use server';

import { revalidateTag } from 'next/cache';
import { getMe } from '@/lib/me';

/**
 * Drop the public branding fetch (`tags: ['branding']` in `getBranding`) after an admin write.
 * Next.js 16 requires a cacheLife profile. `{ expire: 0 }` expires the tag and marks the
 * client router cache stale. Do not call `refresh()` after this: it overwrites that flag
 * with dynamic-only and leaves the cached brand in place.
 */
export async function revalidateBranding(): Promise<void> {
    const me = await getMe();
    if (!me.is_admin) return;
    revalidateTag('branding', { expire: 0 });
}
