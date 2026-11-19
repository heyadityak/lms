<?php

declare(strict_types=1);

namespace App\Support;

/** Branding view model, built per request from Settings (never cached in a long-lived object, D-27). */
final readonly class Brand
{
    public function __construct(
        public string $name,
        public string $primary,
        public string $accent,
        public string $contrast,
        public string $logoUrl,
        public string $faviconUrl,
    ) {}

    public static function fromSettings(Settings $settings): self
    {
        // Colours are validated on write and again here, so a bad row can never inject CSS (D-16).
        $color = fn (string $key): string => BrandColor::isValidHex($v = $settings->get($key)) ? $v : Settings::default($key);
        $primary = $color('brand.primary_color');

        return new self(
            name: (string) $settings->get('brand.name'),
            primary: $primary,
            accent: $color('brand.accent_color'),
            contrast: BrandColor::contrast($primary),
            logoUrl: self::assetUrl('logo', $settings->get('brand.logo_path')),
            faviconUrl: self::assetUrl('favicon', $settings->get('brand.favicon_path')),
        );
    }

    /** Always GET /branding/{asset} (bundled default when not overridden, D-43); `v` busts caches on change. */
    private static function assetUrl(string $asset, mixed $path): string
    {
        return url("/branding/{$asset}").'?v='.(is_string($path) && $path !== '' ? substr(sha1($path), 0, 12) : 'default');
    }
}
