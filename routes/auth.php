<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\KeycloakLoginController;
use Illuminate\Support\Facades\Route;

// Keycloak OIDC (platform-owned). Password reset/change live in Keycloak, not here.
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [KeycloakLoginController::class, 'redirect'])->name('login');
    Route::get('/auth/callback', [KeycloakLoginController::class, 'callback'])->name('auth.callback');
});
Route::post('/logout', [KeycloakLoginController::class, 'logout'])->middleware('auth')->name('logout');
