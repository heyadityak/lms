<?php

declare(strict_types=1);

use App\Http\Controllers\ApiDocsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public contract + viewer (docs/api/openapi.yaml is the source of truth).
Route::middleware('module:api')->group(function (): void {
    Route::get('/openapi.yaml', [ApiDocsController::class, 'spec'])->name('api.spec');
    Route::get('/docs', [ApiDocsController::class, 'ui'])->name('api.docs');
});

// /api/v1: Keycloak Bearer JWT, module gate, Redis rate limit (X-RateLimit-* headers), Idempotency-Key.
Route::prefix('v1')->name('api.v1.')->middleware(['auth:keycloak', 'module:api', 'throttle:api', 'idempotent'])->group(function (): void {
    // Platform smoke endpoint; domain replaces it with UserResource.
    Route::get('/me', fn (Request $request): array => ['data' => $request->user()->only(['id', 'name', 'email', 'role'])])->name('me');
});
