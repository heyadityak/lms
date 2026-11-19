<?php

declare(strict_types=1);

namespace App\Support;

/** Hex colour validation + readable text colour (D-16). Pure, unit-tested. */
final class BrandColor
{
    public static function isValidHex(mixed $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /** `#000000` or `#ffffff`, whichever reads better on $hex (WCAG relative luminance). */
    public static function contrast(string $hex): string
    {
        $channel = function (int $offset) use ($hex): float {
            $c = hexdec(substr($hex, $offset, 2)) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(1) + 0.7152 * $channel(3) + 0.0722 * $channel(5);

        // Contrast with black (L+0.05)/0.05 beats white 1.05/(L+0.05) when L > ~0.179.
        return $luminance > 0.179 ? '#000000' : '#ffffff';
    }
}
