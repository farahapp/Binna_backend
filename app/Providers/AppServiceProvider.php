<?php

namespace App\Providers;

use App\Auth\PlatformGuard;
use Illuminate\Support\Facades\Auth;
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
        //
        // Explicit glob+sort guarantees deterministic alphabetical load order on
        // all filesystems, including Oracle Cloud Linux where directory readdir()
        // order is not guaranteed. loadMigrationsFrom(directory) alone is NOT
        // safe on Linux — it relies on OS inode order.
        //
        // Contract enforced by: tests/Unit/SharedMigrationLoadOrderTest.php
        $sharedMigrations = glob(database_path('migrations/shared/*.php')) ?: [];
        sort($sharedMigrations, SORT_STRING);
        $this->loadMigrationsFrom($sharedMigrations);

        // Register the custom platform-token guard driver.
        //
        // PlatformGuard is a stateless bearer-token guard that queries
        // platform_personal_access_tokens on the shared 'mysql' connection.
        // It is entirely independent of Sanctum's global PersonalAccessToken
        // model (App\IAM\PersonalAccessToken, 'tenant' connection).
        //
        // Used by: config/auth.php 'platform-token' guard entry.
        // Applied to: routes/api.php platform route group via auth:platform-token.
        Auth::extend('platform-token', function ($app, string $name, array $config) {
            return new PlatformGuard($app['request']);
        });
    }
}
