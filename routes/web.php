<?php

use App\Enums\RoleName;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', fn () => to_route('auth.google.redirect'))
        ->name('login');

    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.google.redirect');

    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('auth.google.callback');
});

Route::post('/logout', [GoogleAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::get('/profile/setup', [ProfileSetupController::class, 'create'])
        ->name('profile.setup');
    Route::post('/profile/setup', [ProfileSetupController::class, 'store'])
        ->name('profile.setup.store');

    Route::middleware('profile.complete')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)
            ->name('dashboard');

        Route::get('/admin/dashboard', AdminDashboardController::class)
            ->middleware('role:'.RoleName::ADMIN->value)
            ->name('admin.dashboard');
    });
});
