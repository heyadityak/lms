<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ApprovalController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HolidayController;
use App\Http\Controllers\Web\LeaveRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->middleware('active')->name('dashboard');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::get('/leave-requests/create', [LeaveRequestController::class, 'create'])->name('leave-requests.create');
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');
    Route::get('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
    Route::post('/leave-requests/{leaveRequest}/approve', [ApprovalController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leaveRequest}/reject', [ApprovalController::class, 'reject'])->name('leave-requests.reject');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');

    Route::get('/holidays', [HolidayController::class, 'index'])->middleware('module:holidays')->name('holidays.index');
});
