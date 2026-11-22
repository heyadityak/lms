/** WCAG 2.2 relative luminance and contrast for branding admin (UI_GUIDELINES §4). */

const HEX = /^#[0-9a-fA-F]{6}$/;

/** Normal text AA: 4.5:1. */
export const WCAG_AA_TEXT = 4.5;

function channel(value: number): number {
    const s = value / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

/** Relative luminance of `#RRGGBB`, or null when the string is not a hex colour. */
export function relativeLuminance(hex: string): number | null {
    if (!HEX.test(hex)) return null;
    const n = Number.parseInt(hex.slice(1), 16);
    return (
        0.2126 * channel((n >> 16) & 255) +
        0.7152 * channel((n >> 8) & 255) +
        0.0722 * channel(n & 255)
    );
}

/** Contrast ratio between two `#RRGGBB` colours, or null when either is invalid. */
export function contrastRatio(a: string, b: string): number | null {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    if (la === null || lb === null) return null;
    const lighter = Math.max(la, lb);
    const darker = Math.min(la, lb);
    return (lighter + 0.05) / (darker + 0.05);
}

/** White (`#FFFFFF`) text on `primary`. `passes` is WCAG 2.2 AA for normal text. */
export function whiteTextContrast(primary: string): { ratio: number; passes: boolean } | null {
    const ratio = contrastRatio('#FFFFFF', primary);
    if (ratio === null) return null;
    return { ratio, passes: ratio >= WCAG_AA_TEXT };
}

/**
 * Warning copy when white text on the primary colour is below 4.5:1.
 * Null when the colour passes or is not a hex colour. Saving stays allowed.
 */
export function contrastWarning(primary: string): string | null {
    const result = whiteTextContrast(primary);
    if (!result || result.passes) return null;
    return `Primary colour fails WCAG contrast with white text (${result.ratio.toFixed(2)}:1, needs 4.5:1).`;
}

/** `#RRGGBB` or null. Accepts an optional leading `#` and ignores an alpha nibble. */
export function normalizeHex(value: string): string | null {
    const raw = value.trim();
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    const hex = withHash.slice(0, 7);
    return HEX.test(hex) ? hex : null;
}
