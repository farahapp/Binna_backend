<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\AuditService;
use Illuminate\Support\ServiceProvider;

/**
 * AuditServiceProvider — registers Audit module services and migrations.
 *
 * Loads Audit migrations from database/migrations/audit/.
 * These are per-tenant migrations applied when a tenant schema connection
 * is active, following the same pattern as IamServiceProvider.
 *
 * Registers AuditService as a singleton — the single authorized entry point
 * for logEvent() capability (MOD-03 / TASK-001C Unit 2).
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
    }

    public function boot(): void
    {
        // Register Audit migration path so artisan migrate can find them.
        // Follows the same pattern as IamServiceProvider::boot().
        $this->loadMigrationsFrom(database_path('migrations/audit'));
    }
}
