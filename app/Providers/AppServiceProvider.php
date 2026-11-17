<?php

namespace App\Providers;

use App\Auth\PlatformGuard;
use App\Auth\RegistrationTokenGuard;
use App\Registration\Applicant;
use App\Registration\Representative;
use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use App\WhatsApp\FakeWhatsAppBridge;
use App\WhatsApp\HttpWhatsAppBridgeClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WhatsAppBridgeContract::class, function ($app) {
            if ($app->environment('testing') || (bool) config('whatsapp.use_fake_bridge', false)) {
                return new FakeWhatsAppBridge;
            }

            return new HttpWhatsAppBridgeClient;
        });
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

        Auth::extend('registration-token', function ($app, string $name, array $config) {
            $provider = config("auth.guards.{$name}.provider");
            $model = config("auth.providers.{$provider}.model");

            return new RegistrationTokenGuard(
                $app['request'],
                $model,
                $config['table'],
                $config['foreign_key'],
            );
        });

        RateLimiter::for('registration-otp', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('registration-otp-ip:'.$request->ip()),
                Limit::perMinute(3)->by('registration-otp-email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('representative-auth', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('representative-auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('representative-auth-email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('platform-web-auth', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('platform-web-auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('platform-web-auth-email:'.hash('sha256', $email)),
            ];
        });
    }
}
