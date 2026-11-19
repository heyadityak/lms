<?php

declare(strict_types=1);

// White-label env defaults (D-14). Admin overrides live in the `settings` table (App\Support\Settings).
return [
    'name' => env('APP_BRAND_NAME', 'Leave Manager'),
    'primary_color' => env('BRAND_PRIMARY_COLOR', '#1f6feb'),
    'accent_color' => env('BRAND_ACCENT_COLOR', '#0e8a16'),
    // Disk for uploaded logo/favicon (D-15, D-24): `s3` everywhere, `branding` (local) only for single-node installs.
    'disk' => env('BRANDING_DISK', 's3'),
];
