<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * NetworkServiceProvider — registers Network module services and migrations.
 *
 * NETWORK-001 — Network Foundation & Profile
 *
 * Follows the same pattern as ProcurementServiceProvider, CRMServiceProvider, etc.
 */
class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Network\NetworkProfileService::class, function ($app) {
            return new \App\Network\NetworkProfileService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        // NETWORK-002 — TenderService
        $this->app->singleton(\App\Network\TenderService::class, function ($app) {
            return new \App\Network\TenderService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        // NETWORK-003 — BidService
        $this->app->singleton(\App\Network\BidService::class, function ($app) {
            return new \App\Network\BidService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        // NETWORK-004 — EvaluationService
        $this->app->singleton(\App\Network\EvaluationService::class, function ($app) {
            return new \App\Network\EvaluationService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        // NETWORK-005 — AwardService
        $this->app->singleton(\App\Network\AwardService::class, function ($app) {
            return new \App\Network\AwardService(
                $app->make(\App\Audit\AuditService::class),
            );
        });

        // Network Profile Verification — authorized implementation
        $this->app->singleton(\App\Network\NetworkProfileVerificationService::class, function ($app) {
            return new \App\Network\NetworkProfileVerificationService(
                $app->make(\App\Audit\AuditService::class),
                $app->make(\App\Notification\NotificationService::class),
            );
        });
    }

    public function boot(): void
    {
        // Register Network migration path so artisan migrate can find them.
        $this->loadMigrationsFrom(
            database_path('migrations/network')
        );
    }
}
