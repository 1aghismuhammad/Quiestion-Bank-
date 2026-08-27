<?php

use App\Enums\RoleName;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialTopicController;
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

        Route::get('/materials', [MaterialController::class, 'index'])
            ->name('materials.index');
        Route::get('/materials/archived', [MaterialController::class, 'archived'])
            ->name('materials.archived');
        Route::get('/materials/create', [MaterialController::class, 'create'])
            ->name('materials.create');
        Route::post('/materials/text', [MaterialController::class, 'storeText'])
            ->name('materials.store-text');
        Route::post('/materials/upload', [MaterialController::class, 'storeUpload'])
            ->name('materials.store-upload');

        Route::scopeBindings()
            ->whereNumber('material')
            ->whereNumber('topic')
            ->group(function (): void {
                Route::get('/materials/{material}', [MaterialController::class, 'show'])
                    ->name('materials.show');
                Route::get('/materials/{material}/edit', [MaterialController::class, 'edit'])
                    ->name('materials.edit');
                Route::patch('/materials/{material}', [MaterialController::class, 'update'])
                    ->name('materials.update');
                Route::post('/materials/{material}/archive', [MaterialController::class, 'archive'])
                    ->name('materials.archive');
                Route::post('/materials/{material}/restore', [MaterialController::class, 'restore'])
                    ->name('materials.restore');

                Route::post('/materials/{material}/topics', [MaterialTopicController::class, 'store'])
                    ->name('materials.topics.store');
                Route::patch('/materials/{material}/topics/{topic}', [MaterialTopicController::class, 'update'])
                    ->name('materials.topics.update');
                Route::delete('/materials/{material}/topics/{topic}', [MaterialTopicController::class, 'destroy'])
                    ->name('materials.topics.destroy');
            });
    });
});
