<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * ProcurementServiceProvider — registers Procurement module migrations.
 *
 * Loads Procurement migrations from database/migrations/procurement/.
 *
 * Follows the same pattern as CostControlServiceProvider, ProjectServiceProvider,
 * CommercialServiceProvider, CRMServiceProvider.
 *
 * NOT registered in TASK-007A:
 *  - ProcurementService (TASK-007B)
 *  - Approval services (TASK-007B)
 *  - Event listeners (TASK-007B)
 *  - HTTP components (TASK-007C)
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Procurement\PurchaseRequestService::class, function ($app) {
            return new \App\Procurement\PurchaseRequestService(
                $app->make(\App\Audit\AuditService::class),
                $app->make(\App\Approval\ApprovalService::class),
            );
        });

        $this->app->singleton(\App\Procurement\RFQService::class, function ($app) {
            return new \App\Procurement\RFQService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        $this->app->singleton(\App\Procurement\PurchaseOrderService::class, function ($app) {
            return new \App\Procurement\PurchaseOrderService(
                $app->make(\App\Audit\AuditService::class),
                $app->make(\App\Approval\ApprovalService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register Procurement migration path so artisan migrate can find them.
        // Follows the same pattern as CostControlServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/procurement')
        );
    }
}
