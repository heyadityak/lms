import type { ThemeConfig } from 'antd';
import type { Schemas } from '@/lib/api/client';

export type Branding = Schemas['PublicBranding'];

const HEX = /^#[0-9a-fA-F]{6}$/;

/**
 * Fallback when GET /api/v1/branding is unreachable. Colours follow the Figma default
 * (UI_GUIDELINES §4). shortcut: approximate values from UI_GUIDELINES, the Figma MCP was not
 * available in phase 9; replace with get_variable_defs (node 484:2) values in phase 12.
 */
export const DEFAULT_BRANDING: Branding = {
    name: 'Leave Manager',
    primary_color: '#3F5BC6',
    accent_color: '#0E8A16',
    primary_contrast: '#FFFFFF',
    logo_url: '/branding/logo',
    favicon_url: '/branding/favicon',
    modules: [],
};

const FONT_FAMILY =
    "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif";

function hex(value: unknown, fallback: string): string {
    return typeof value === 'string' && HEX.test(value) ? value : fallback;
}

/** Brand → antd v6 theme (CSS variables are always on in v6; token table in UI_GUIDELINES §4). */
export function themeFromBranding(branding: Partial<Branding> | null | undefined): ThemeConfig {
    const primary = hex(branding?.primary_color, DEFAULT_BRANDING.primary_color);
    return {
        token: {
            colorPrimary: primary,
            colorLink: primary,
            colorInfo: primary,
            colorSuccess: hex(branding?.accent_color, DEFAULT_BRANDING.accent_color),
            borderRadius: 6,
            fontFamily: FONT_FAMILY,
        },
        components: {
            Button: {
                primaryColor: hex(branding?.primary_contrast, DEFAULT_BRANDING.primary_contrast),
            },
        },
    };
}
