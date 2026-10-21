<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Regression check for the build: resolving the Blade compiler at boot (as laravel/cashier-paddle does)
        // needs storage/framework/views during `composer install` (package:discover), which Arco stages.
        Blade::directive('arco', fn (): string => '');
    }
}
