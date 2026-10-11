<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * SubcontractServiceProvider — registers Subcontracts module services and migrations.
 *
 * Loads Subcontracts migrations from database/migrations/subcontracts/.
 * Registers SubcontractAgreementService and SubcontractProgressService singletons.
 *
 * Follows the same pattern as InventoryServiceProvider, ProcurementServiceProvider,
 * CostControlServiceProvider, ProjectServiceProvider.
 *
 * NOT registered in TASK-009B:
 *  - HTTP Controllers / Routes (TASK-009C)
 *
 * TASK-009B — Subcontract Application/Service Layer
 */
class SubcontractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Subcontracts\Services\SubcontractAgreementService::class,
            function ($app) {
                return new \App\Subcontracts\Services\SubcontractAgreementService(
                    $app->make(\App\Audit\AuditService::class),
                    $app->make(\App\Approval\ApprovalService::class),
                );
            }
        );

        $this->app->singleton(
            \App\Subcontracts\Services\SubcontractProgressService::class,
            function ($app) {
                return new \App\Subcontracts\Services\SubcontractProgressService(
                    $app->make(\App\Audit\AuditService::class),
                    $app->make(\App\Approval\ApprovalService::class),
                );
            }
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            database_path('migrations/subcontracts')
        );
    }
}
