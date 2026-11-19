<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// Public contract + viewer, no auth (SYSTEM_DESIGN §6.2: the spec holds no secrets).
Route::middleware('module:api')->group(function (): void {
    Route::get('/openapi.yaml', [ApiDocsController::class, 'spec'])->name('api.spec');
    Route::get('/docs', [ApiDocsController::class, 'ui'])->name('api.docs');
});

// Public branding for the Next.js theme and nav (D-43): no auth, no `module:api`, per-IP limiter `public`.
Route::get('/v1/branding', [BrandingController::class, 'show'])->middleware('throttle:public')->name('api.v1.branding');

// /api/v1: module gate (404; the first-party BFF passes, D-43) → Keycloak Bearer JWT (401, D-20) → `active` (401) → Redis rate limit (X-RateLimit-*, 429).
// `idempotent` on POST /leave-requests (not /preview, D-43) (+ POST /admin/users in phase 9) only.
Route::prefix('v1')->name('api.v1.')->middleware(['module:api', 'auth:keycloak-jwt', 'active', 'throttle:api'])->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::get('/me/balances', [MeController::class, 'balances'])->name('me.balances');
    Route::get('/me/leave-requests', [MeController::class, 'leaveRequests'])->name('me.leave-requests');

    Route::get('/leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::get('/holidays', [HolidayController::class, 'index'])->middleware('module:holidays')->name('holidays.index');

    Route::get('/leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->middleware('idempotent')->name('leave-requests.store');
    Route::post('/leave-requests/preview', [LeaveRequestController::class, 'preview'])->name('leave-requests.preview');
    Route::get('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::post('/leave-requests/{leaveRequest}/approve', [ApprovalController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/reject', [ApprovalController::class, 'reject'])->name('leave-requests.reject');
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals');
});
