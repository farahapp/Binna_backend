<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * CostControlServiceProvider — registers Cost Control module migrations.
 *
 * Loads Cost Control migrations from database/migrations/cost_control/.
 *
 * Follows the same pattern as ProjectServiceProvider, CommercialServiceProvider,
 * CRMServiceProvider, AuditServiceProvider, and DocumentServiceProvider.
 *
 * NOT registered in TASK-006A:
 *  - CostControlService (TASK-006B)
 *  - Approval services (TASK-006B)
 *  - Event listeners (TASK-006B)
 *  - HTTP components (TASK-006C)
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class CostControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\CostControl\CostControlService::class, function ($app) {
            return new \App\CostControl\CostControlService(
                $app->make(\App\Audit\AuditService::class),
                $app->make(\App\Approval\ApprovalService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register Cost Control migration path so artisan migrate can find them.
        // Follows the same pattern as ProjectServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/cost_control')
        );
    }
}
