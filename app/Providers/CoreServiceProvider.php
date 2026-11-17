<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\AuditService;
use App\Core\CollaborationService;
use App\Core\CoreProjectService;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CoreProjectService::class, function ($app) {
            return new CoreProjectService(
                $app->make(AuditService::class),
            );
        });

        $this->app->singleton(CollaborationService::class, function ($app) {
            return new CollaborationService(
                $app->make(AuditService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            database_path('migrations/core')
        );
    }
}
