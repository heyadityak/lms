import { describe, expect, it } from 'vitest';
import { assetFileError } from './brand-asset';

const file = (name: string, type: string, size = 100) => ({ name, type, size });

describe('assetFileError', () => {
    it('rejects SVG for logo and favicon', () => {
        expect(assetFileError('logo', file('mark.svg', 'image/svg+xml'))).toMatch(/SVG/);
        expect(assetFileError('logo', file('mark.png', 'image/svg+xml'))).toMatch(/SVG/);
        expect(assetFileError('favicon', file('icon.svg', 'image/svg+xml'))).toMatch(/SVG/);
    });

    it('accepts PNG, JPEG, and WebP logos and PNG or ICO favicons', () => {
        expect(assetFileError('logo', file('a.png', 'image/png'))).toBeNull();
        expect(assetFileError('logo', file('a.jpg', 'image/jpeg'))).toBeNull();
        expect(assetFileError('logo', file('a.webp', 'image/webp'))).toBeNull();
        expect(assetFileError('favicon', file('f.png', 'image/png'))).toBeNull();
        expect(assetFileError('favicon', file('f.ico', 'image/x-icon'))).toBeNull();
        expect(assetFileError('favicon', file('f.ico', ''))).toBeNull();
    });

    it('rejects a JPEG favicon and an oversized logo', () => {
        expect(assetFileError('favicon', file('f.jpg', 'image/jpeg'))).toMatch(/PNG or ICO/);
        expect(assetFileError('logo', file('a.png', 'image/png', 1_048_577))).toMatch(/1 MB/);
    });
});
