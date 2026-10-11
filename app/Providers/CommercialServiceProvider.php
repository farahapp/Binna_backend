<?php

declare(strict_types=1);

namespace App\Providers;

use App\Commercial\CommercialPolicy;
use App\Commercial\CommercialService;
use App\Commercial\CommercialVisibilityService;
use App\Commercial\Contract;
use App\Commercial\BOQ;
use App\Commercial\Quotation;
use App\Commercial\Tender;
use App\Commercial\ContractVariation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * CommercialServiceProvider — registers Commercial module services and migrations.
 *
 * Loads Commercial migrations from database/migrations/commercial/.
 * Registers CommercialService as a singleton — the application/service layer
 * for the Commercial module (A1 MOD-05 / TASK-004B1).
 *
 * CAP-012: Registers CommercialVisibilityService (field-level) and
 * CommercialPolicy (record-level + tenant isolation).
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 */
class CommercialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register CommercialService as a singleton (TASK-004B1).
        $this->app->singleton(CommercialService::class);

        // CAP-012: Register CommercialVisibilityService as a singleton.
        $this->app->singleton(CommercialVisibilityService::class);
    }

    public function boot(): void
    {
        // Register Commercial migration path so artisan migrate can find them.
        $this->loadMigrationsFrom(
            database_path('migrations/commercial')
        );

        // CAP-012: Register CommercialPolicy for record-level + tenant isolation.
        Gate::policy(Contract::class, CommercialPolicy::class);
        Gate::policy(BOQ::class, CommercialPolicy::class);
        Gate::policy(Quotation::class, CommercialPolicy::class);
        Gate::policy(Tender::class, CommercialPolicy::class);
        Gate::policy(ContractVariation::class, CommercialPolicy::class);
    }
}
