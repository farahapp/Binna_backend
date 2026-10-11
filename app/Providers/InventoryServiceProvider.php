<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * InventoryServiceProvider — registers Inventory module services and migrations.
 *
 * Loads Inventory migrations from database/migrations/inventory/.
 * Registers GoodsReceiptService and StockManagementService singletons.
 *
 * Follows the same pattern as ProcurementServiceProvider, CostControlServiceProvider,
 * ProjectServiceProvider, CommercialServiceProvider, CRMServiceProvider.
 *
 * NOT registered in TASK-008B:
 *  - HTTP Controllers / Routes (TASK-008C)
 *
 * TASK-008B — Inventory Application/Service Layer
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Inventory\GoodsReceiptService::class, function ($app) {
            return new \App\Inventory\GoodsReceiptService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        $this->app->singleton(\App\Inventory\StockManagementService::class, function ($app) {
            return new \App\Inventory\StockManagementService(
                auditService: $app->make(\App\Audit\AuditService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register Inventory migration path so artisan migrate can find them.
        // Follows the same pattern as ProcurementServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/inventory')
        );
    }
}
