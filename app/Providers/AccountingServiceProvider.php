<?php
declare(strict_types=1);
namespace App\Providers;
use App\Accounting\AccountingService;
use Illuminate\Support\ServiceProvider;

/**
 * AccountingServiceProvider — registers Accounting module migrations and services.
 * Loads Accounting migrations from database/migrations/accounting/.
 * Registers AccountingService singleton.
 * TASK-014A — Accounting Domain/Data Foundation
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountingService::class, function ($app) {
            return new AccountingService(
                auditService: $app->make(\App\Audit\AuditService::class),
            );
        });
    }
    public function boot(): void
    {
        $this->loadMigrationsFrom(
            database_path('migrations/accounting')
        );
    }
}
