import { describe, expect, it } from 'vitest';
import { contrastRatio, contrastWarning, whiteTextContrast } from './contrast';

describe('white text contrast', () => {
    it('fails WCAG AA for white text on yellow and passes on the default primary', () => {
        const yellow = whiteTextContrast('#FFFF00');
        const primary = whiteTextContrast('#3A5FBE');
        expect(yellow?.passes).toBe(false);
        expect(yellow && yellow.ratio).toBeLessThan(4.5);
        expect(primary?.passes).toBe(true);
        expect(contrastRatio('#000000', '#FFFFFF')).toBe(21);
        expect(contrastWarning('#FFFF00')).toMatch(/fails WCAG contrast with white text/);
        expect(contrastWarning('#3A5FBE')).toBeNull();
        expect(contrastWarning('red')).toBeNull();
    });
});
