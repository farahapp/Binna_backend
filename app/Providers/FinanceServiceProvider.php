<?php
declare(strict_types=1);
namespace App\Providers;
use App\Finance\FinanceService;
use Illuminate\Support\ServiceProvider;

/**
 * FinanceServiceProvider — registers Finance module migrations and services.
 * Loads Finance migrations from database/migrations/finance/.
 * Registers FinanceService singleton.
 * TASK-013A — Finance Domain/Data Foundation
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FinanceService::class, function ($app) {
            return new FinanceService(
                auditService: $app->make(\App\Audit\AuditService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            database_path('migrations/finance')
        );
    }
}
