import { describe, expect, it } from 'vitest';
import { DEFAULT_BRANDING, FIGMA, activeNavColor, themeFromBranding } from './brand';

describe('themeFromBranding', () => {
    it('maps brand colours to antd tokens', () => {
        const theme = themeFromBranding({
            primary_color: '#112233',
            accent_color: '#445566',
            primary_contrast: '#000000',
        });
        expect(theme.token).toMatchObject({
            colorPrimary: '#112233',
            colorLink: '#112233',
            colorInfo: '#112233',
            colorSuccess: '#445566',
            borderRadius: 4,
            controlHeight: 40,
            colorBgLayout: FIGMA.page,
        });
        expect(theme.components?.Button?.primaryColor).toBe('#000000');
        expect(theme.components?.Layout?.siderBg).toBe('#112233');
    });

    it('falls back to the default brand for invalid or missing colours', () => {
        const theme = themeFromBranding({
            primary_color: 'red; background:url(x)',
            accent_color: '#12345',
        });
        expect(theme.token?.colorPrimary).toBe(DEFAULT_BRANDING.primary_color);
        expect(theme.token?.colorSuccess).toBe(DEFAULT_BRANDING.accent_color);
        expect(theme.components?.Button?.primaryColor).toBe(DEFAULT_BRANDING.primary_contrast);
        expect(themeFromBranding(null).token?.colorPrimary).toBe(FIGMA.primary);
    });
});

describe('activeNavColor', () => {
    it('is the Figma navy for the default primary and a darker shade otherwise', () => {
        expect(activeNavColor('#3a5fbe')).toBe(FIGMA.navy);
        expect(activeNavColor('#FF8000')).toBe('#994d00');
        expect(activeNavColor('#000000')).toBe('#000000');
    });
});
