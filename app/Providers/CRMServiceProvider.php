<?php

declare(strict_types=1);

namespace App\Providers;

use App\CRM\CRMService;
use Illuminate\Support\ServiceProvider;

/**
 * CRMServiceProvider — registers CRM module services and migrations.
 *
 * Loads CRM migrations from database/migrations/crm/.
 * Registers CRMService as a singleton — the application/service layer
 * for the CRM module (A1 MOD-04 / TASK-003B).
 *
 * TASK-003B — CRM Application/Service Layer
 */
class CRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register CRMService as a singleton (TASK-003B).
        // Follows the same pattern as AuditServiceProvider and DocumentServiceProvider.
        $this->app->singleton(CRMService::class);
    }

    public function boot(): void
    {
        // Register CRM migration path so artisan migrate can find them.
        // Follows the same pattern as AuditServiceProvider::boot().
        $this->loadMigrationsFrom(database_path('migrations/crm'));
    }
}
