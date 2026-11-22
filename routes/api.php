<?php

declare(strict_types=1);

use App\Enums\Module;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ApprovalDelegationController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DepartmentLeaveQuotaController;
use App\Http\Controllers\Api\V1\EncashmentController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveAttachmentController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\LedgerEntryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\PublicBrandingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TeamCalendarController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkingWeekController;
use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// Public contract + viewer, no auth (SYSTEM_DESIGN §6.2: the spec holds no secrets).
Route::middleware('module:api')->group(function (): void {
    Route::get('/openapi.yaml', [ApiDocsController::class, 'spec'])->name('api.spec');
    Route::get('/docs', [ApiDocsController::class, 'ui'])->name('api.docs');
});

// Public branding for the Next.js theme and nav (D-43): no auth, no `module:api`, per-IP limiter `public`.
Route::get('/v1/branding', [PublicBrandingController::class, 'show'])->middleware('throttle:public')->name('api.v1.branding');

// /api/v1: module gate (404; the first-party BFF passes, D-43) → Keycloak Bearer JWT (401, D-20) → `active` (401) → Redis rate limit (X-RateLimit-*, 429).
// `idempotent` on POST /leave-requests (not /preview, D-43) and POST /admin/users.
Route::prefix('v1')->name('api.v1.')->middleware(['module:api', 'auth:keycloak-jwt', 'active', 'throttle:api'])->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::get('/me/balances', [MeController::class, 'balances'])->name('me.balances');
    Route::get('/me/leave-requests', [MeController::class, 'leaveRequests'])->name('me.leave-requests');
    Route::get('/me/ledger-entries', [LedgerEntryController::class, 'mine'])->name('me.ledger-entries');
    Route::post('/me/avatar', [AvatarController::class, 'store'])->name('me.avatar.store');
    Route::delete('/me/avatar', [AvatarController::class, 'destroy'])->name('me.avatar.destroy');
    Route::get('/me/approval-delegations', [ApprovalDelegationController::class, 'show'])->name('me.approval-delegations.show');
    Route::post('/me/approval-delegations', [ApprovalDelegationController::class, 'store'])->name('me.approval-delegations.store');
    Route::delete('/me/approval-delegations', [ApprovalDelegationController::class, 'destroy'])->name('me.approval-delegations.destroy');

    Route::get('/team-calendar', [TeamCalendarController::class, 'index'])->name('team-calendar');

    // HR admin (phase 13). Writes are hr_admin via policies (403); reads follow the spec x-roles.
    Route::get('/leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::post('/leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::get('/leave-types/{leaveType}', [LeaveTypeController::class, 'show'])->name('leave-types.show');
    Route::patch('/leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');

    Route::middleware('module:holidays')->group(function (): void {
        Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::get('/holidays/{holiday}', [HolidayController::class, 'show'])->name('holidays.show');
        Route::patch('/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
    });

    Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::put('/departments/{department}/leave-quotas', [DepartmentLeaveQuotaController::class, 'update'])->name('departments.leave-quotas.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    Route::get('/users/{user}/avatar', [AvatarController::class, 'show'])->name('users.avatar');
    Route::post('/users/{user}/encashments', [EncashmentController::class, 'store'])->name('users.encashments.store');
    Route::get('/users/{user}/balances', [UserController::class, 'balances'])->name('users.balances');
    Route::get('/users/{user}/ledger-entries', [LedgerEntryController::class, 'index'])->name('users.ledger-entries.index');
    Route::post('/users/{user}/ledger-entries', [LedgerEntryController::class, 'store'])->name('users.ledger-entries.store');

    Route::middleware(['module:reports', 'role:hr_admin'])->group(function (): void {
        Route::get('/reports/leave-requests', [ReportController::class, 'leaveRequests'])->name('reports.leave-requests');
        Route::get('/reports/balances', [ReportController::class, 'balances'])->name('reports.balances');
    });

    Route::get('/leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->middleware('idempotent')->name('leave-requests.store');
    Route::post('/leave-requests/preview', [LeaveRequestController::class, 'preview'])->name('leave-requests.preview');
    Route::get('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::get('/leave-requests/{leaveRequest}/pdf', [LeaveRequestController::class, 'pdf'])->name('leave-requests.pdf');
    Route::get('/leave-requests/{leaveRequest}/attachments', [LeaveAttachmentController::class, 'show'])->name('leave-requests.attachments.show');
    Route::post('/leave-requests/{leaveRequest}/attachments', [LeaveAttachmentController::class, 'store'])->name('leave-requests.attachments.store');
    Route::delete('/leave-requests/{leaveRequest}/attachments', [LeaveAttachmentController::class, 'destroy'])->name('leave-requests.attachments.destroy');
    Route::post('/leave-requests/{leaveRequest}/approve', [ApprovalController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/reject', [ApprovalController::class, 'reject'])->name('leave-requests.reject');
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals');

    // Working week (UC-H16): GET any role, PATCH hr_admin. Does not rewrite stored leave days (D-30).
    Route::get('/settings/working-week', [WorkingWeekController::class, 'show'])->name('settings.working-week.show');
    Route::patch('/settings/working-week', [WorkingWeekController::class, 'update'])->name('settings.working-week.update');

    // Branding (UC-B1–B4, UC-B6): GET any role, writes role `admin`.
    Route::get('/settings/branding', [BrandingController::class, 'show'])->name('settings.branding.show');
    Route::patch('/settings/branding', [BrandingController::class, 'update'])->name('settings.branding.update');
    Route::delete('/settings/branding', [BrandingController::class, 'reset'])->name('settings.branding.reset');
    Route::put('/settings/branding/assets/{asset}', [BrandingController::class, 'upload'])->whereIn('asset', ['logo', 'favicon'])->name('settings.branding.assets.update');
    Route::delete('/settings/branding/assets/{asset}', [BrandingController::class, 'destroy'])->whereIn('asset', ['logo', 'favicon'])->name('settings.branding.assets.destroy');

    // Modules (UC-B5): role `admin`. `api` may be disabled; the BFF bypass stays in EnsureModuleEnabled.
    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::patch('/modules/{module}', [ModuleController::class, 'update'])->whereIn('module', array_map(fn (Module $module): string => $module->value, Module::cases()))->name('modules.update');

    // Identity admin (UC-U1–U6). 404 when `user_management` is off. No local fallback when Keycloak is down (D-32).
    Route::middleware(['module:user_management', 'admin'])->group(function (): void {
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('idempotent')->name('admin.users.store');
        Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::post('/admin/users/{user}/enable', [AdminUserController::class, 'enable'])->name('admin.users.enable');
        Route::post('/admin/users/{user}/disable', [AdminUserController::class, 'disable'])->name('admin.users.disable');
        Route::put('/admin/users/{user}/roles', [AdminUserController::class, 'roles'])->name('admin.users.roles');
        Route::post('/admin/users/{user}/password-reset', [AdminUserController::class, 'passwordReset'])->name('admin.users.password-reset');
    });
});
