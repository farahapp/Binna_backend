<?php

declare(strict_types=1);

namespace App\Providers;

use App\Foundation\DomainMigrator;
use App\Foundation\IamMigrator;
use App\Foundation\SwitchToTenantSchemaForJob;
use App\Foundation\TenantBaselineMigrator;
use App\Foundation\TenantContextResolver;
use App\Foundation\TenantProvisioner;
use App\Foundation\TenantSchemaGrantor;
use Illuminate\Support\ServiceProvider;

/**
 * FoundationServiceProvider — registers Foundation/SaaS services.
 *
 * Kept minimal: no business logic, no IAM, no Audit.
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContextResolver::class);

        $this->app->singleton(TenantBaselineMigrator::class);

        // IamMigrator — IAM-PROV-001: registered as singleton so it can be
        // resolved independently (e.g. for idempotent re-runs or testing).
        $this->app->singleton(IamMigrator::class);

        // TenantSchemaGrantor — DEC-MYSQL-001: CLI-only, performs GRANT via provisioning connection.
        $this->app->singleton(TenantSchemaGrantor::class);

        // DomainMigrator — PROV-DOM-001: applies all per-tenant domain migrations (Batch 3).
        $this->app->singleton(DomainMigrator::class);

        $this->app->singleton(TenantProvisioner::class, function ($app) {
            return new TenantProvisioner(
                $app->make(TenantBaselineMigrator::class),
                $app->make(IamMigrator::class),
                $app->make(TenantSchemaGrantor::class),
                $app->make(DomainMigrator::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
