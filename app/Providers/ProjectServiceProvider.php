<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * ProjectServiceProvider — registers Projects module migrations.
 *
 * Loads Projects migrations from database/migrations/projects/.
 *
 * Follows the same pattern as CommercialServiceProvider, CRMServiceProvider,
 * AuditServiceProvider, and DocumentServiceProvider.
 *
 * NOT registered in TASK-005A:
 *  - ProjectService (TASK-005B)
 *  - Approval services (TASK-005B)
 *  - Event listeners (TASK-005B)
 *  - HTTP components (TASK-005C)
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class ProjectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Projects\ProjectsService::class, function ($app) {
            return new \App\Projects\ProjectsService(
                $app->make(\App\Audit\AuditService::class)
            );
        });
    }

    public function boot(): void
    {
        // Register Projects migration path so artisan migrate can find them.
        // Follows the same pattern as CommercialServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/projects')
        );
    }
}
