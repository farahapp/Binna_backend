<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * HrServiceProvider — registers HR module migrations and services.
 *
 * Loads HR migrations from database/migrations/hr/.
 * Registers HrService singleton.
 *
 * Follows the same pattern as SubcontractServiceProvider, InventoryServiceProvider,
 * ProcurementServiceProvider, CostControlServiceProvider, ProjectServiceProvider.
 *
 * NOT registered in TASK-010A:
 *  - Full HR business workflows (TASK-010B)
 *  - HTTP Controllers / Routes (TASK-010C)
 *  - Payroll/WPS (CAP-091 excluded — OD-008 IMPL-BLOCKING)
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\HR\HrService::class, function ($app) {
            return new \App\HR\HrService(
                $app->make(\App\Audit\AuditService::class),
                $app->make(\App\Approval\ApprovalService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register HR migration path so artisan migrate can find them.
        // Follows the same pattern as SubcontractServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/hr')
        );
    }
}
