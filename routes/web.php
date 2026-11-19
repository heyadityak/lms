<?php

declare(strict_types=1);

use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Laravel is API-only (D-43): no pages, no session/cookie/CSRF middleware. Loaded without the `web` group.
Route::get('/healthz', [HealthController::class, 'live']);
Route::get('/readyz', [HealthController::class, 'ready']);
Route::get('/branding/{asset}', BrandingAssetController::class)->whereIn('asset', ['logo', 'favicon'])->name('branding.asset');
