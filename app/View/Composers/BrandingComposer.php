<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Support\Brand;
use App\Support\Settings;
use Illuminate\View\View;

/**
 * Shares `$brand` with the app's views, resolved from the request-scoped Settings (D-27).
 * Namespaced views (`errors::500`, mail/pagination components) are skipped so error pages never need the DB.
 */
final class BrandingComposer
{
    public function compose(View $view): void
    {
        if (str_contains($view->name(), '::')) {
            return;
        }
        $view->with('brand', Brand::fromSettings(app(Settings::class)));
    }
}
