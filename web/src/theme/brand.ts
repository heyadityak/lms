import type { ThemeConfig } from 'antd';
import type { Schemas } from '@/lib/api/client';

export type Branding = Schemas['PublicBranding'];

const HEX = /^#[0-9a-fA-F]{6}$/;

/** Fixed Figma tokens (page "Final One", node 484:2); not per customer in MVP (UI_GUIDELINES §4). */
export const FIGMA = {
    primary: '#3A5FBE',
    navy: '#234088',
    heading: '#44477B',
    text: '#616188',
    border: '#D0D1DE',
    subtle: '#E7E8EE',
    page: '#F2F4F8',
    cardShadow: '0 4px 15px rgba(35, 64, 136, 0.05), 0 3px 35px -4px rgba(52, 76, 106, 0.1)',
    headerShadow: '2px 2px 10px 3px rgba(0, 0, 0, 0.05)',
} as const;

/** Fallback when GET /api/v1/branding is unreachable; colours = Figma defaults. */
export const DEFAULT_BRANDING: Branding = {
    name: 'Leave Manager',
    primary_color: FIGMA.primary,
    // shortcut: Figma has no accent variable; emerald from its success tile family, until a designer picks one.
    accent_color: '#047857',
    primary_contrast: '#FFFFFF',
    logo_url: '/branding/logo',
    favicon_url: '/branding/favicon',
    modules: [],
};

// Open Sans is self-hosted by next/font (root layout sets --font-open-sans); system stack as fallback.
const FONT_FAMILY =
    "var(--font-open-sans), -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

function hex(value: unknown, fallback: string): string {
    return typeof value === 'string' && HEX.test(value) ? value : fallback;
}

/** Figma navy for the default primary; for a customer primary, the same colour 40% towards black. */
export function activeNavColor(primary: string): string {
    if (primary.toUpperCase() === FIGMA.primary) return FIGMA.navy;
    const n = parseInt(primary.slice(1), 16);
    const ch = (shift: number) => Math.round(((n >> shift) & 255) * 0.6);
    return `#${((ch(16) << 16) | (ch(8) << 8) | ch(0)).toString(16).padStart(6, '0')}`;
}

/** Brand → antd v6 theme (CSS variables are always on in v6; token table in UI_GUIDELINES §4). */
export function themeFromBranding(branding: Partial<Branding> | null | undefined): ThemeConfig {
    const primary = hex(branding?.primary_color, DEFAULT_BRANDING.primary_color);
    const contrast = hex(branding?.primary_contrast, DEFAULT_BRANDING.primary_contrast);
    return {
        token: {
            colorPrimary: primary,
            colorLink: primary,
            colorInfo: primary,
            colorSuccess: hex(branding?.accent_color, DEFAULT_BRANDING.accent_color),
            colorTextHeading: FIGMA.heading,
            colorTextSecondary: FIGMA.text,
            // Description and placeholder greys from the default palette miss 4.5:1 on the page background.
            colorTextDescription: FIGMA.text,
            colorTextPlaceholder: FIGMA.text,
            colorBorderSecondary: FIGMA.subtle,
            colorBgLayout: FIGMA.page,
            borderRadius: 4,
            controlHeight: 40,
            fontFamily: FONT_FAMILY,
        },
        components: {
            Button: { primaryColor: contrast, fontWeight: 600 },
            Layout: {
                siderBg: primary,
                headerBg: '#FFFFFF',
                headerHeight: 64,
                headerPadding: '0 24px',
                bodyBg: FIGMA.page,
            },
            Table: { headerColor: FIGMA.heading, headerBg: '#FFFFFF', cellPaddingBlock: 18 },
            Card: { headerFontSize: 16 },
        },
    };
}
