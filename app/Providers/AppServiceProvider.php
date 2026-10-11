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
        // Register the shared migration path (Track 1 — NEW-002).
        // The default migrations/ path is kept for Laravel's own tables.
        $this->loadMigrationsFrom(database_path('migrations/shared'));
    }
}
