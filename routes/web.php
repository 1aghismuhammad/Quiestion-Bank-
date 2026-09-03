<?php

use App\Enums\RoleName;
use App\Http\Controllers\Account\SubscriptionController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SubscriptionUpgradeController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialTopicController;
use App\Http\Controllers\ProfileSetupController;
use App\Http\Controllers\QuestionSetController;
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

        Route::get('/account/subscription', [SubscriptionController::class, 'show'])
            ->name('account.subscription.show');
        Route::post('/account/subscription/confirm', [SubscriptionController::class, 'confirm'])
            ->middleware('throttle:10,1')
            ->name('account.subscription.confirm');

        Route::get('/admin/dashboard', AdminDashboardController::class)
            ->middleware('role:'.RoleName::ADMIN->value)
            ->name('admin.dashboard');

        Route::middleware('role:'.RoleName::ADMIN->value)->group(function (): void {
            Route::get('/admin/subscription-upgrades', [SubscriptionUpgradeController::class, 'index'])
                ->name('admin.subscription-upgrades.index');
            Route::get('/admin/subscription-upgrades/{upgradeRequest}', [SubscriptionUpgradeController::class, 'show'])
                ->whereNumber('upgradeRequest')
                ->name('admin.subscription-upgrades.show');
            Route::post('/admin/subscription-upgrades/{upgradeRequest}/approve', [SubscriptionUpgradeController::class, 'approve'])
                ->whereNumber('upgradeRequest')
                ->name('admin.subscription-upgrades.approve');
            Route::post('/admin/subscription-upgrades/{upgradeRequest}/reject', [SubscriptionUpgradeController::class, 'reject'])
                ->whereNumber('upgradeRequest')
                ->name('admin.subscription-upgrades.reject');
            Route::post('/admin/subscription-upgrades/{upgradeRequest}/cancel', [SubscriptionUpgradeController::class, 'cancel'])
                ->whereNumber('upgradeRequest')
                ->name('admin.subscription-upgrades.cancel');
        });

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

        Route::get('/generations', [GenerationController::class, 'index'])
            ->name('generations.index');

        Route::get('/question-sets', [QuestionSetController::class, 'index'])
            ->name('question-sets.index');

        Route::whereNumber('questionSet')
            ->group(function (): void {
                Route::get('/question-sets/{questionSet}', [QuestionSetController::class, 'show'])
                    ->name('question-sets.show');
                Route::get('/question-sets/{questionSet}/edit', [QuestionSetController::class, 'edit'])
                    ->name('question-sets.edit');
                Route::patch('/question-sets/{questionSet}', [QuestionSetController::class, 'update'])
                    ->name('question-sets.update');
                Route::post('/question-sets/{questionSet}/publish', [QuestionSetController::class, 'publish'])
                    ->name('question-sets.publish');
            });

        Route::whereNumber('generation')
            ->group(function (): void {
                Route::get('/generations/{generation}', [GenerationController::class, 'show'])
                    ->name('generations.show');
                Route::get('/generations/{generation}/status', [GenerationController::class, 'status'])
                    ->name('generations.status');
                Route::post('/generations/{generation}/retry', [GenerationController::class, 'retry'])
                    ->name('generations.retry');
                Route::post('/generations/{generation}/question-sets', [QuestionSetController::class, 'storeFromGeneration'])
                    ->name('question-sets.import');
            });

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

                Route::get('/materials/{material}/generations/create', [GenerationController::class, 'create'])
                    ->name('generations.create');
                Route::post('/materials/{material}/generations', [GenerationController::class, 'store'])
                    ->name('generations.store');

                Route::post('/materials/{material}/topics', [MaterialTopicController::class, 'store'])
                    ->name('materials.topics.store');
                Route::patch('/materials/{material}/topics/{topic}', [MaterialTopicController::class, 'update'])
                    ->name('materials.topics.update');
                Route::delete('/materials/{material}/topics/{topic}', [MaterialTopicController::class, 'destroy'])
                    ->name('materials.topics.destroy');
            });
    });
});
