<?php

declare(strict_types=1);

namespace App\Providers;

use App\Equipment\EquipmentService;
use Illuminate\Support\ServiceProvider;

/**
 * EquipmentServiceProvider — registers Equipment module migrations and services.
 *
 * Loads Equipment migrations from database/migrations/equipment/.
 * Registers EquipmentService singleton.
 *
 * Follows the same pattern as HrServiceProvider, SubcontractServiceProvider,
 * InventoryServiceProvider, ProcurementServiceProvider, CostControlServiceProvider.
 *
 * TASK-011B — Equipment Application/Service Layer
 */
class EquipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EquipmentService::class, function ($app) {
            return new EquipmentService(
                auditService: $app->make(\App\Audit\AuditService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register Equipment migration path so artisan migrate can find them.
        // Follows the same pattern as HrServiceProvider::boot().
        $this->loadMigrationsFrom(
            database_path('migrations/equipment')
        );
    }
}
