export type BrandAsset = 'logo' | 'favicon';

const LOGO_TYPES = new Set(['image/png', 'image/jpeg', 'image/webp']);
const FAVICON_TYPES = new Set(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', '']);

/**
 * Client check before `PUT /settings/branding/assets/{asset}` (UC-B3, UC-B4).
 * The API still rejects bad files. SVG is never accepted.
 */
export function assetFileError(
    asset: BrandAsset,
    file: { name: string; type: string; size: number },
): string | null {
    const name = file.name.toLowerCase();
    if (name.endsWith('.svg') || file.type === 'image/svg+xml') {
        return 'SVG files are not allowed.';
    }
    if (asset === 'logo') {
        const extOk = /\.(png|jpe?g|webp)$/.test(name);
        const typeOk = file.type === '' || LOGO_TYPES.has(file.type);
        if (!extOk || !typeOk) return 'Logo must be PNG, JPEG, or WebP.';
        if (file.size > 1_048_576) return 'Logo must be 1 MB or smaller.';
        return null;
    }
    const extOk = /\.(png|ico)$/.test(name);
    const typeOk = FAVICON_TYPES.has(file.type);
    if (!extOk || !typeOk) return 'Favicon must be PNG or ICO.';
    if (file.size > 262_144) return 'Favicon must be 256 KB or smaller.';
    return null;
}
