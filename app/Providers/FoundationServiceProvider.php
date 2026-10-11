<?php

declare(strict_types=1);

namespace App\Providers;

use App\Foundation\TenantBaselineMigrator;
use App\Foundation\TenantContextResolver;
use App\Foundation\TenantProvisioner;
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

        $this->app->singleton(TenantProvisioner::class, function ($app) {
            return new TenantProvisioner(
                $app->make(TenantBaselineMigrator::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
