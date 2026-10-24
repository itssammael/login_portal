<?php

namespace App\Providers;

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
        // Proactive PHP runtime hardening against PHP2Shell and fingerprinting
        @ini_set('session.upload_progress.enabled', '0');
        @ini_set('expose_php', '0');
    }
}
