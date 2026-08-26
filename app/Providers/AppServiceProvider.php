<?php

namespace App\Providers;

use App\Contracts\Materials\MaterialFileStore;
use App\Services\Materials\MaterialStorageService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaterialFileStore::class, MaterialStorageService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
