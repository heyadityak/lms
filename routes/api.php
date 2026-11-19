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

// /api/v1: module gate, Keycloak Bearer JWT (D-20), Redis rate limit (X-RateLimit-* headers), Idempotency-Key.
Route::prefix('v1')->name('api.v1.')->middleware(['module:api', 'auth:keycloak-jwt', 'throttle:api', 'idempotent'])->group(function (): void {
    // shortcut: phase-3 stub proving the guard; domain replaces it with UserResource (public_id as id) per openapi.yaml.
    Route::get('/me', fn (Request $request): array => ['data' => $request->user()->only(['id', 'name', 'email', 'role', 'is_admin'])])->name('me');
});
